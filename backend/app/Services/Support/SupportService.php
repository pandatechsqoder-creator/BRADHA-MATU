<?php

namespace App\Services\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SupportService
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = ['open', 'pending', 'resolved', 'closed'];

    public const CATEGORIES = ['general', 'account', 'billing', 'technical', 'stocktake', 'pos', 'integration'];

    private const SLA_HOURS = [
        'low' => 48,
        'normal' => 24,
        'high' => 8,
        'urgent' => 4,
    ];

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForTenant(Tenant $tenant, array $filters = []): array
    {
        return $this->baseQuery($filters)
            ->where('support_tickets.tenant_id', $tenant->id)
            ->get()
            ->map(fn (SupportTicket $ticket) => $this->payload($ticket))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForPlatform(array $filters = []): array
    {
        return $this->baseQuery($filters)
            ->withoutGlobalScopes()
            ->with('tenant')
            ->get()
            ->map(fn (SupportTicket $ticket) => $this->payload($ticket))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function create(Tenant $tenant, User $user, array $data): array
    {
        $priority = $data['priority'] ?? 'normal';

        $ticket = SupportTicket::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'reference' => (string) Str::ulid(),
            'subject' => $data['subject'],
            'description' => $data['description'],
            'category' => $data['category'] ?? 'general',
            'priority' => $priority,
            'status' => 'open',
            'sla_due_at' => now()->addHours(self::SLA_HOURS[$priority] ?? 24),
        ]);

        SupportTicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'body' => $data['description'],
            'is_internal' => false,
        ]);

        $ticket->forceFill(['last_reply_at' => now()])->save();

        $this->notifications->notify(
            $this->notifications->platformSupportUsers(),
            'support',
            "New {$priority} support ticket from {$tenant->name}",
            $data['subject'],
            ['ticket_id' => $ticket->uuid, 'priority' => $priority, 'tenant' => $tenant->name],
        );

        $this->audit->log('support.ticket.created', $ticket, null, null, [
            'reference' => $ticket->reference,
            'priority' => $priority,
            'category' => $ticket->category,
        ], $tenant->id);

        return $this->payload($ticket->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function show(SupportTicket $ticket): array
    {
        return $this->payload($ticket->load(['messages.author', 'creator', 'assignee', 'tenant']), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function reply(SupportTicket $ticket, User $author, string $body, bool $internal, bool $fromPlatform): array
    {
        SupportTicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_id' => $author->id,
            'body' => $body,
            'is_internal' => $fromPlatform && $internal,
        ]);

        $attributes = ['last_reply_at' => now()];

        if ($fromPlatform && $ticket->first_response_at === null) {
            $attributes['first_response_at'] = now();
        }

        if (in_array($ticket->status, ['resolved', 'closed'], true)) {
            $attributes['status'] = 'open';
            $attributes['resolved_at'] = null;
            $attributes['closed_at'] = null;
        }

        $ticket->forceFill($attributes)->save();

        if ($fromPlatform) {
            if (! $internal && $ticket->creator) {
                $this->notifications->notify(
                    $ticket->creator,
                    'support',
                    "Support replied to {$ticket->subject}",
                    Str::limit($body, 160),
                    ['ticket_id' => $ticket->uuid, 'reference' => $ticket->reference],
                    $ticket->tenant_id,
                );
            }
        } else {
            $targets = $ticket->assignee
                ? collect([$ticket->assignee])
                : $this->notifications->platformSupportUsers();

            $this->notifications->notify(
                $targets,
                'support',
                "Customer replied to {$ticket->subject}",
                Str::limit($body, 160),
                ['ticket_id' => $ticket->uuid, 'reference' => $ticket->reference],
                $ticket->tenant_id,
            );
        }

        $this->audit->log('support.ticket.replied', $ticket, null, null, [
            'internal' => $internal && $fromPlatform,
            'from_platform' => $fromPlatform,
        ], $ticket->tenant_id);

        return $this->payload($ticket->fresh()->load(['messages.author', 'creator', 'assignee', 'tenant']), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function update(SupportTicket $ticket, array $data, User $actor, bool $fromPlatform): array
    {
        $before = ['status' => $ticket->status, 'priority' => $ticket->priority, 'assigned_to' => $ticket->assigned_to];

        if (isset($data['status'])) {
            $this->applyStatus($ticket, $data['status']);
        }

        if ($fromPlatform && isset($data['priority'])) {
            $ticket->priority = $data['priority'];
        }

        if ($fromPlatform && array_key_exists('assigned_to', $data)) {
            if ($data['assigned_to'] === null) {
                $ticket->assigned_to = null;
            } else {
                $assignee = User::where('uuid', $data['assigned_to'])->first();
                if ($assignee === null) {
                    throw ValidationException::withMessages(['assigned_to' => 'The assignee could not be found.']);
                }
                $ticket->assigned_to = $assignee->id;
            }
        }

        $ticket->save();

        if ($fromPlatform && $ticket->creator && ($before['status'] !== $ticket->status)) {
            $this->notifications->notify(
                $ticket->creator,
                'support',
                "Support ticket {$ticket->subject} is now {$ticket->status}",
                null,
                ['ticket_id' => $ticket->uuid, 'reference' => $ticket->reference],
                $ticket->tenant_id,
            );
        }

        $this->audit->log('support.ticket.updated', $ticket, null, $before, [
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'assigned_to' => $ticket->assigned_to,
        ], $ticket->tenant_id);

        return $this->payload($ticket->fresh()->load(['messages.author', 'creator', 'assignee', 'tenant']), true);
    }

    private function applyStatus(SupportTicket $ticket, string $status): void
    {
        $ticket->status = $status;

        if ($status === 'resolved') {
            $ticket->resolved_at = now();
        } elseif ($status === 'closed') {
            $ticket->closed_at = now();
            if ($ticket->resolved_at !== null && $ticket->resolved_at->isAfter($ticket->closed_at)) {
                $ticket->resolved_at = $ticket->closed_at;
            }
        } elseif (in_array($status, ['open', 'pending'], true)) {
            $ticket->resolved_at = null;
            $ticket->closed_at = null;
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<SupportTicket>
     */
    private function baseQuery(array $filters)
    {
        $query = SupportTicket::query()
            ->withCount('messages')
            ->orderByDesc('last_reply_at')
            ->orderByDesc('created_at')
            ->limit(min(max((int) ($filters['limit'] ?? 100), 1), 200));

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('subject', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%"));
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SupportTicket $ticket, bool $withMessages = false): array
    {
        $payload = [
            'id' => $ticket->uuid,
            'reference' => $ticket->reference,
            'tenant' => $ticket->tenant ? [
                'id' => $ticket->tenant->uuid,
                'name' => $ticket->tenant->name,
            ] : null,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'sla_due_at' => $ticket->sla_due_at?->toIso8601String(),
            'first_response_at' => $ticket->first_response_at?->toIso8601String(),
            'resolved_at' => $ticket->resolved_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'last_reply_at' => $ticket->last_reply_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'overdue' => $ticket->isOverdue(),
            'messages_count' => $ticket->messages_count ?? $ticket->messages()->count(),
            'created_by' => $ticket->creator?->name,
            'assigned_to' => $ticket->assignee ? [
                'id' => $ticket->assignee->uuid,
                'name' => $ticket->assignee->name,
            ] : null,
        ];

        if ($withMessages) {
            $payload['messages'] = $ticket->messages
                ->map(fn (SupportTicketMessage $message) => [
                    'id' => $message->uuid,
                    'body' => $message->body,
                    'is_internal' => $message->is_internal,
                    'author' => $message->author?->name,
                    'created_at' => $message->created_at?->toIso8601String(),
                ])
                ->all();
        }

        return $payload;
    }
}
