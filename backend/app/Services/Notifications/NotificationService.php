<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Collection;

final class NotificationService
{
    public const EVENTS = [
        'stocktake_updates',
        'pos_alerts',
        'finance',
        'support',
        'reports',
        'security',
    ];

    public const DIGEST_FREQUENCIES = ['instant', 'daily', 'weekly', 'off'];

    /**
     * @param  User|iterable<User>  $recipients
     */
    public function notify(
        User|iterable $recipients,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
        ?int $tenantId = null,
    ): int {
        $users = $recipients instanceof User ? collect([$recipients]) : collect($recipients);
        $created = 0;

        foreach ($users->unique('id') as $user) {
            if (! $user instanceof User) {
                continue;
            }

            if (! $this->preferencesFor($user)->in_app_enabled) {
                continue;
            }

            Notification::create([
                'user_id' => $user->id,
                'tenant_id' => $tenantId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data ?: null,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listFor(User $user, array $filters = []): array
    {
        $query = Notification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at');

        if (($filters['filter'] ?? null) === 'unread') {
            $query->whereNull('read_at');
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->limit(min(max((int) ($filters['limit'] ?? 50), 1), 200))
            ->get()
            ->map(fn (Notification $notification) => $this->payload($notification))
            ->all();
    }

    public function unreadCount(User $user): int
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    public function markRead(User $user, Notification $notification): array
    {
        abort_unless($notification->user_id === $user->id, 404);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $this->payload($notification);
    }

    public function markAllRead(User $user): int
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function preferences(User $user): array
    {
        return $this->payloadForPreferences($this->preferencesFor($user));
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePreferences(User $user, array $data): array
    {
        $preferences = $this->preferencesFor($user);

        if (array_key_exists('in_app_enabled', $data)) {
            $preferences->in_app_enabled = (bool) $data['in_app_enabled'];
        }
        if (array_key_exists('email_enabled', $data)) {
            $preferences->email_enabled = (bool) $data['email_enabled'];
        }
        if (array_key_exists('digest_frequency', $data)) {
            $preferences->digest_frequency = $data['digest_frequency'];
        }
        if (array_key_exists('event_preferences', $data)) {
            $preferences->event_preferences = $data['event_preferences'] ?: null;
        }

        $preferences->save();

        return $this->payloadForPreferences($preferences);
    }

    private function preferencesFor(User $user): NotificationPreference
    {
        return NotificationPreference::firstOrCreate(
            ['user_id' => $user->id],
            [
                'in_app_enabled' => true,
                'email_enabled' => true,
                'digest_frequency' => 'instant',
                'event_preferences' => null,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Notification $notification): array
    {
        return [
            'id' => $notification->uuid,
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            'data' => (object) ($notification->data ?? []),
            'tenant_id' => $notification->tenant_id,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadForPreferences(NotificationPreference $preferences): array
    {
        return [
            'in_app_enabled' => $preferences->in_app_enabled,
            'email_enabled' => $preferences->email_enabled,
            'digest_frequency' => $preferences->digest_frequency,
            'event_preferences' => (object) ($preferences->event_preferences ?? []),
            'events' => self::EVENTS,
            'digest_frequencies' => self::DIGEST_FREQUENCIES,
        ];
    }

    /**
     * Platform support agents who should hear about new tickets.
     *
     * @return Collection<int, User>
     */
    public function platformSupportUsers(): Collection
    {
        return User::query()
            ->whereHas('roles', function ($query): void {
                $query->whereNull('user_roles.tenant_id')
                    ->whereIn('roles.slug', ['super-admin', 'support-manager', 'support-agent']);
            })
            ->get();
    }
}
