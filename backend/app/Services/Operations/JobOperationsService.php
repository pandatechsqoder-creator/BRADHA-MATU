<?php

namespace App\Services\Operations;

use App\Models\JobAssignment;
use App\Models\JobAttendance;
use App\Models\JobChecklist;
use App\Models\JobChecklistItem;
use App\Models\JobChecklistResponse;
use App\Models\JobProcedure;
use App\Models\JobProcedureAcknowledgement;
use App\Models\JobProgressEvent;
use App\Models\JobSiteCheckin;
use App\Models\JobSiteCheckout;
use App\Models\StocktakeJob;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Stocktake\StocktakeWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class JobOperationsService
{
    private const OPS_JOB_STATUSES = [
        'approved_for_execution', 'awaiting_assignment', 'assigned', 'awaiting_worker_acceptance',
        'scheduled', 'in_progress', 'paused', 'count_submitted', 'quality_review', 'recount_required',
    ];

    public function __construct(
        private readonly ProcedureService $procedures,
        private readonly StocktakeWorkflow $workflow,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function workerPayload(JobAssignment $assignment): array
    {
        $assignment = JobAssignment::with([
            'job.tenant', 'profile', 'checkins', 'checkouts', 'attendanceRecord', 'procedureAcknowledgements',
        ])->whereKey($assignment->id)->firstOrFail();

        $job = $assignment->job;
        $procedure = $this->procedures->activeProcedure($job);
        $ack = $this->acknowledgement($assignment, $procedure);
        $checklists = $this->checklistPayloads($assignment, $procedure);
        $checkin = $assignment->checkins->sortByDesc('checked_in_at')->first();
        $checkout = $assignment->checkouts->sortByDesc('checked_out_at')->first();
        $readiness = $this->computeReadiness($assignment, $job, $procedure, $ack, $checklists, $checkin, $checkout);
        $progress = $this->progressForAssignment($assignment);

        return [
            'assignment' => [
                'id' => $assignment->uuid,
                'status' => $assignment->status,
                'role' => $assignment->role,
                'compensation' => [
                    'amount' => (float) $assignment->compensation_amount,
                    'currency' => $assignment->currency,
                ],
                'job' => $job ? [
                    'id' => $job->uuid,
                    'reference' => $job->reference,
                    'title' => $job->title,
                    'status' => $job->status,
                    'status_label' => StocktakeWorkflow::LABELS[$job->status] ?? ucfirst(str_replace('_', ' ', $job->status)),
                    'priority' => $job->priority,
                    'count_method' => $job->count_method,
                    'scope_label' => $job->scope['label'] ?? null,
                    'preferred_start_at' => $job->preferred_start_at?->toIso8601String(),
                    'deadline_at' => $job->deadline_at?->toIso8601String(),
                    'tenant' => $job->tenant?->name,
                    'required_team_size' => $job->required_team_size,
                ] : null,
            ],
            'procedure' => $procedure ? [
                'id' => $procedure->uuid,
                'version' => (int) $procedure->version,
                'title' => $procedure->title,
                'summary' => $procedure->summary,
                'require_acknowledgement' => (bool) $procedure->require_acknowledgement,
                'assigned_at' => $procedure->assigned_at?->toIso8601String(),
                'acknowledged' => $ack !== null,
                'acknowledged_at' => $ack?->acknowledged_at?->toIso8601String(),
                'steps' => $procedure->procedureSteps->map(fn ($step) => [
                    'position' => (int) $step->position,
                    'title' => $step->title,
                    'instructions' => $step->instructions,
                    'required' => (bool) $step->required,
                    'evidence_required' => (bool) $step->evidence_required,
                ])->values()->all(),
            ] : null,
            'checklists' => $checklists,
            'checkin' => $checkin ? $this->checkinPayload($checkin) : null,
            'checkout' => $checkout ? $this->checkoutPayload($checkout) : null,
            'attendance' => $assignment->attendanceRecord ? $this->attendancePayload($assignment->attendanceRecord) : null,
            'readiness' => $readiness,
            'progress' => $progress,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function acknowledge(JobAssignment $assignment, User $worker, ?string $ip = null, ?string $userAgent = null): array
    {
        DB::transaction(function () use ($assignment, $worker, $ip, $userAgent): void {
            $assignment = JobAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $this->assertOwnedBy($assignment, $worker);

            $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($assignment->job_id)->firstOrFail();
            $this->assertAccepted($assignment);

            $procedure = $this->procedures->activeProcedure($job);
            if ($procedure === null) {
                throw ValidationException::withMessages(['procedure' => ['No job SOP has been assigned yet.']]);
            }

            $ack = JobProcedureAcknowledgement::firstOrCreate(
                ['job_procedure_id' => $procedure->id, 'assignment_id' => $assignment->id],
                [
                    'tenant_id' => $job->tenant_id,
                    'acknowledged_by' => $worker->id,
                    'acknowledged_at' => now(),
                    'ip_address' => $ip,
                    'user_agent' => $userAgent ? Str::limit($userAgent, 250, '') : null,
                ],
            );

            if ($ack->wasRecentlyCreated) {
                $this->recordProgress($job, $assignment, 'sop_acknowledged', 25, 'SOP v'.$procedure->version.' acknowledged', $worker, 'worker');
                $this->audit->log('sop.job.acknowledged', $ack, null, null, [
                    'job' => $job->reference,
                    'procedure_version' => $procedure->version,
                ], $job->tenant_id, $worker->id);
            }
        });

        return $this->workerPayload($assignment->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function respondChecklist(JobAssignment $assignment, string $itemUuid, array $data, User $worker): array
    {
        DB::transaction(function () use ($assignment, $itemUuid, $data, $worker): void {
            $assignment = JobAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $this->assertOwnedBy($assignment, $worker);
            $this->assertAccepted($assignment);

            $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($assignment->job_id)->firstOrFail();
            $procedure = $this->procedures->activeProcedure($job);

            $item = JobChecklistItem::with('checklist')->where('uuid', $itemUuid)->firstOrFail();

            if ($item->checklist === null || $item->checklist->job_id !== $job->id) {
                abort(404);
            }

            if ($procedure === null || $item->checklist->job_procedure_id !== $procedure->id) {
                throw ValidationException::withMessages(['checklist' => ['This checklist item is not part of the active SOP.']]);
            }

            $value = $this->normalizeResponse($item, (string) $data['value']);

            JobChecklistResponse::updateOrCreate(
                ['checklist_item_id' => $item->id, 'assignment_id' => $assignment->id],
                [
                    'tenant_id' => $job->tenant_id,
                    'value' => $value,
                    'notes' => $data['notes'] ?? null,
                    'responded_by' => $worker->id,
                    'responded_at' => now(),
                ],
            );

            $checklists = $this->checklistPayloads($assignment, $procedure);
            $progress = $this->checklistProgress($checklists);

            $alreadyLogged = JobProgressEvent::where('job_id', $job->id)
                ->where('assignment_id', $assignment->id)
                ->where('stage', 'checklist_completed')
                ->exists();

            if ($progress['complete'] && ! $alreadyLogged) {
                $this->recordProgress($job, $assignment, 'checklist_completed', 50, 'Required pre-count checklist completed', $worker, 'worker');
            }

            $this->audit->log('operations.checklist.responded', $item, null, null, [
                'job' => $job->reference,
                'item' => $item->label,
                'value' => $value,
            ], $job->tenant_id, $worker->id);
        });

        return $this->workerPayload($assignment->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function checkIn(JobAssignment $assignment, array $data, User $worker): array
    {
        DB::transaction(function () use ($assignment, $data, $worker): void {
            $assignment = JobAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $this->assertOwnedBy($assignment, $worker);
            $this->assertAccepted($assignment);

            $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($assignment->job_id)->lockForUpdate()->firstOrFail();

            if (! in_array($job->status, ['scheduled', 'in_progress'], true)) {
                throw ValidationException::withMessages([
                    'check_in' => ['The job is not scheduled for field work ('.(StocktakeWorkflow::LABELS[$job->status] ?? $job->status).').'],
                ]);
            }

            if ($this->openCheckin($assignment) !== null) {
                return;
            }

            $procedure = $this->procedures->activeProcedure($job);
            $ack = $this->acknowledgement($assignment, $procedure);
            $checklists = $this->checklistPayloads($assignment, $procedure);
            $progress = $this->checklistProgress($checklists);

            $blockers = [];
            if ($procedure === null) {
                $blockers[] = 'A job SOP has not been assigned.';
            } elseif ($procedure->require_acknowledgement && $ack === null) {
                $blockers[] = 'Acknowledge the job SOP before checking in.';
            }
            if ($progress['answered'] < $progress['required']) {
                $blockers[] = 'Complete '.($progress['required'] - $progress['answered']).' required checklist item(s) before checking in.';
            }

            if ($blockers !== []) {
                throw ValidationException::withMessages(['check_in' => $blockers]);
            }

            $checkin = JobSiteCheckin::create([
                'tenant_id' => $job->tenant_id,
                'job_id' => $job->id,
                'assignment_id' => $assignment->id,
                'site_label' => $data['site_label'] ?? ($job->scope['label'] ?? null),
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'distance_m' => $data['distance_m'] ?? null,
                'method' => $data['method'] ?? 'manual',
                'notes' => $data['notes'] ?? null,
                'checked_in_by' => $worker->id,
                'checked_in_at' => now(),
            ]);

            $late = $job->preferred_start_at !== null && now()->greaterThan($job->preferred_start_at->copy()->addMinutes(15));

            JobAttendance::updateOrCreate(
                ['job_id' => $job->id, 'assignment_id' => $assignment->id],
                [
                    'tenant_id' => $job->tenant_id,
                    'status' => $late ? 'late' : 'present',
                    'checkin_id' => $checkin->id,
                    'scheduled_start_at' => $job->preferred_start_at,
                    'checked_in_at' => now(),
                    'recorded_by' => $worker->id,
                ],
            );

            $this->recordProgress($job, $assignment, 'checked_in', 75, 'Checked in at '.($checkin->site_label ?? 'the site'), $worker, 'worker');

            if ($job->status === 'scheduled') {
                $this->workflow->transition($job, 'in_progress', $worker, 'Worker checked in on site.');
                $this->recordProgress($job, $assignment, 'count_started', 100, 'Count started after check-in', $worker, 'worker');
            }

            $this->audit->log('operations.check_in', $checkin, null, null, [
                'job' => $job->reference,
                'site' => $checkin->site_label,
                'method' => $checkin->method,
                'late' => $late,
            ], $job->tenant_id, $worker->id);
        });

        return $this->workerPayload($assignment->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function checkOut(JobAssignment $assignment, array $data, User $worker): array
    {
        DB::transaction(function () use ($assignment, $data, $worker): void {
            $assignment = JobAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $this->assertOwnedBy($assignment, $worker);

            $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($assignment->job_id)->firstOrFail();

            $checkin = JobSiteCheckin::where('assignment_id', $assignment->id)
                ->latest('checked_in_at')
                ->first();

            if ($checkin === null) {
                throw ValidationException::withMessages(['check_out' => ['Check in before checking out.']]);
            }

            $existing = JobSiteCheckout::where('assignment_id', $assignment->id)
                ->where('checkin_id', $checkin->id)
                ->first();

            if ($existing !== null) {
                return;
            }

            $checkout = JobSiteCheckout::create([
                'tenant_id' => $job->tenant_id,
                'job_id' => $job->id,
                'assignment_id' => $assignment->id,
                'checkin_id' => $checkin->id,
                'notes' => $data['notes'] ?? null,
                'checked_out_by' => $worker->id,
                'checked_out_at' => now(),
            ]);

            $minutes = $checkin->checked_in_at !== null
                ? max(1, (int) $checkin->checked_in_at->diffInMinutes(now()))
                : null;

            JobAttendance::updateOrCreate(
                ['job_id' => $job->id, 'assignment_id' => $assignment->id],
                [
                    'tenant_id' => $job->tenant_id,
                    'status' => 'checked_out',
                    'checkin_id' => $checkin->id,
                    'checkout_id' => $checkout->id,
                    'scheduled_start_at' => $job->preferred_start_at,
                    'checked_in_at' => $checkin->checked_in_at,
                    'checked_out_at' => now(),
                    'minutes_on_site' => $minutes,
                    'recorded_by' => $worker->id,
                ],
            );

            $this->recordProgress($job, $assignment, 'checked_out', 75, 'Checked out after '.($minutes ?? 0).' minutes on site', $worker, 'worker');

            $this->audit->log('operations.check_out', $checkout, null, null, [
                'job' => $job->reference,
                'minutes_on_site' => $minutes,
            ], $job->tenant_id, $worker->id);
        });

        return $this->workerPayload($assignment->fresh());
    }

    /**
     * Hard gate for the count engine (Phase 10). Throws a 422 with blockers.
     *
     * @return array<string, mixed>
     */
    public function assertCanStartCount(JobAssignment $assignment, User $worker): array
    {
        $assignment = JobAssignment::with(['job', 'profile', 'checkins', 'checkouts', 'procedureAcknowledgements'])
            ->whereKey($assignment->id)
            ->firstOrFail();
        $this->assertOwnedBy($assignment, $worker);

        $job = $assignment->job;
        $procedure = $this->procedures->activeProcedure($job);
        $ack = $this->acknowledgement($assignment, $procedure);
        $checklists = $this->checklistPayloads($assignment, $procedure);
        $checkin = $assignment->checkins->sortByDesc('checked_in_at')->first();
        $checkout = $assignment->checkouts->sortByDesc('checked_out_at')->first();
        $readiness = $this->computeReadiness($assignment, $job, $procedure, $ack, $checklists, $checkin, $checkout);

        if (! $readiness['can_start_count']) {
            throw ValidationException::withMessages([
                'count' => array_merge(['You cannot start counting yet.'], $readiness['blockers']),
            ]);
        }

        return $readiness;
    }

    /**
     * @return array<string, mixed>
     */
    public function platformPayload(StocktakeJob $job): array
    {
        $job->loadMissing('tenant');
        $procedure = $this->procedures->jobProcedurePayload($job);

        $assignments = JobAssignment::with([
            'profile.user', 'checkins', 'checkouts', 'attendanceRecord', 'procedureAcknowledgements',
        ])->where('job_id', $job->id)
            ->whereIn('status', ['offered', 'accepted'])
            ->orderBy('id')
            ->get();

        $activeProcedure = $this->procedures->activeProcedure($job);

        $rows = $assignments->map(function (JobAssignment $assignment) use ($job, $activeProcedure): array {
            $ack = $this->acknowledgement($assignment, $activeProcedure);
            $checklists = $this->checklistPayloads($assignment, $activeProcedure);
            $checkin = $assignment->checkins->sortByDesc('checked_in_at')->first();
            $checkout = $assignment->checkouts->sortByDesc('checked_out_at')->first();
            $readiness = $this->computeReadiness($assignment, $job, $activeProcedure, $ack, $checklists, $checkin, $checkout);
            $progress = $this->checklistProgress($checklists);

            return [
                'id' => $assignment->uuid,
                'status' => $assignment->status,
                'role' => $assignment->role,
                'profile' => [
                    'id' => $assignment->profile?->uuid,
                    'name' => $assignment->profile?->user?->name,
                    'level' => $assignment->profile?->level,
                ],
                'acknowledged_at' => $ack?->acknowledged_at?->toIso8601String(),
                'checklist_required' => $progress['required'],
                'checklist_answered' => $progress['answered'],
                'checkin' => $checkin ? $this->checkinPayload($checkin) : null,
                'checkout' => $checkout ? $this->checkoutPayload($checkout) : null,
                'attendance' => $assignment->attendanceRecord ? $this->attendancePayload($assignment->attendanceRecord) : null,
                'readiness' => $readiness,
            ];
        })->values();

        $accepted = $rows->where('status', 'accepted');
        $acceptedIds = $assignments->where('status', 'accepted')->pluck('id')->all();
        $checkedIn = $accepted->filter(fn (array $row) => $row['checkin'] !== null
            && ($row['checkout'] === null || strtotime($row['checkin']['checked_in_at']) > strtotime($row['checkout']['checked_out_at'])));
        $acknowledged = $accepted->filter(fn (array $row) => $row['acknowledged_at'] !== null);
        $percent = $accepted->isEmpty() ? 0 : (int) round($accepted->avg(fn (array $row) => $row['readiness']['percent']));
        $blockers = $accepted->flatMap(fn (array $row) => $row['readiness']['blockers'])->unique()->values()->all();

        $checklistRows = $activeProcedure?->checklists->map(function (JobChecklist $checklist) use ($acceptedIds): array {
            $itemIds = $checklist->items->pluck('id');
            $required = $checklist->items->where('required', true)->count();
            $answers = $itemIds->isEmpty() || $acceptedIds === []
                ? 0
                : JobChecklistResponse::whereIn('checklist_item_id', $itemIds)
                    ->whereIn('assignment_id', $acceptedIds)
                    ->distinct()
                    ->count('checklist_item_id');

            return [
                'id' => $checklist->uuid,
                'name' => $checklist->name,
                'kind' => $checklist->kind,
                'required' => (bool) $checklist->required,
                'items_total' => $checklist->items->count(),
                'items_required' => $required,
                'items_answered' => $answers,
            ];
        })->values()->all() ?? [];

        $events = JobProgressEvent::where('job_id', $job->id)
            ->orderBy('occurred_at')
            ->limit(100)
            ->get()
            ->map(fn (JobProgressEvent $event) => [
                'stage' => $event->stage,
                'percent' => (int) $event->percent,
                'message' => $event->message,
                'actor_type' => $event->actor_type,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])->values()->all();

        $canStart = $job->status === 'in_progress'
            && $accepted->isNotEmpty()
            && $accepted->every(fn (array $row) => $row['readiness']['checked_in']);

        return [
            'job' => $this->jobSummary($job),
            'procedure' => $procedure,
            'assignments' => $rows->all(),
            'checklists' => $checklistRows,
            'summary' => [
                'team_required' => (int) $job->required_team_size,
                'team_accepted' => $accepted->count(),
                'checked_in' => $checkedIn->count(),
                'sop_acknowledged' => $acknowledged->count(),
                'can_start_count' => $canStart,
                'blockers' => $blockers,
                'percent' => $percent,
                'stage' => $this->jobStage($job, $accepted->pluck('readiness')->all()),
                'next_action' => $blockers[0] ?? null,
            ],
            'progress' => [
                'percent' => $percent,
                'stage' => $this->jobStage($job, $accepted->pluck('readiness')->all()),
                'events' => $events,
            ],
            'attendance' => JobAttendance::where('job_id', $job->id)
                ->get()
                ->map(fn (JobAttendance $attendance) => $this->attendancePayload($attendance))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $jobs = StocktakeJob::withoutGlobalScope('tenant')
            ->with(['tenant'])
            ->whereIn('status', self::OPS_JOB_STATUSES)
            ->orderBy('preferred_start_at')
            ->limit(100)
            ->get();

        $rows = $jobs->map(function (StocktakeJob $job): array {
            $assignments = JobAssignment::with(['profile.user', 'checkins', 'checkouts', 'attendanceRecord', 'procedureAcknowledgements'])
                ->where('job_id', $job->id)
                ->whereIn('status', ['offered', 'accepted'])
                ->get();

            $procedure = $this->procedures->activeProcedure($job);
            $accepted = $assignments->where('status', 'accepted');

            $readinessRows = $accepted->map(function (JobAssignment $assignment) use ($job, $procedure): array {
                $ack = $this->acknowledgement($assignment, $procedure);
                $checklists = $this->checklistPayloads($assignment, $procedure);
                $checkin = $assignment->checkins->sortByDesc('checked_in_at')->first();
                $checkout = $assignment->checkouts->sortByDesc('checked_out_at')->first();

                return $this->computeReadiness($assignment, $job, $procedure, $ack, $checklists, $checkin, $checkout);
            });

            $percent = $readinessRows->isEmpty() ? 0 : (int) round($readinessRows->avg('percent'));
            $blockers = $readinessRows->flatMap(fn (array $row) => $row['blockers'])->unique()->values()->all();
            $checkedIn = $readinessRows->where('checked_in', true)->count();
            $required = $readinessRows->max('checklist_required') ?? 0;
            $answered = $readinessRows->max('checklist_answered') ?? 0;

            $procedurePayload = $procedure ? [
                'title' => $procedure->title,
                'version' => (int) $procedure->version,
                'acknowledged' => $readinessRows->where('sop_acknowledged', true)->count(),
                'assigned' => true,
            ] : null;

            $lastEvent = JobProgressEvent::where('job_id', $job->id)->max('occurred_at');

            return [
                'job' => [
                    ...$this->jobSummary($job),
                    'preferred_start_at' => $job->preferred_start_at?->toIso8601String(),
                    'deadline_at' => $job->deadline_at?->toIso8601String(),
                ],
                'team' => [
                    'required' => (int) $job->required_team_size,
                    'accepted' => $accepted->count(),
                    'checked_in' => $checkedIn,
                ],
                'procedure' => $procedurePayload,
                'checklists' => [
                    'required' => (int) $required,
                    'answered' => (int) $answered,
                    'complete' => $required === 0 || $answered >= $required,
                ],
                'progress' => [
                    'percent' => $percent,
                    'stage' => $this->jobStage($job, $readinessRows->all()),
                ],
                'next_action' => $blockers[0] ?? null,
                'blockers' => $blockers,
                'is_overdue' => $job->deadline_at !== null
                    && $job->deadline_at->isPast()
                    && ! in_array($job->status, ['count_submitted', 'quality_review'], true),
                'last_activity_at' => $lastEvent !== null
                    ? Carbon::parse($lastEvent)->toIso8601String()
                    : $job->updated_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'summary' => [
                'active_jobs' => count($rows),
                'in_progress' => $jobs->where('status', 'in_progress')->count(),
                'awaiting_check_in' => $jobs->where('status', 'scheduled')->count(),
                'unassigned' => $jobs->whereIn('status', ['approved_for_execution', 'awaiting_assignment'])->count(),
                'sop_pending' => collect($rows)->filter(fn (array $row) => $row['procedure'] === null || $row['procedure']['acknowledged'] === 0)->count(),
                'overdue' => collect($rows)->where('is_overdue', true)->count(),
            ],
            'jobs' => $rows,
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Tenant-safe operational view: progress, SOP and attendance.
     * Never includes worker compensation or platform margin.
     *
     * @return array<string, mixed>
     */
    public function tenantPayload(StocktakeJob $job): array
    {
        $job->loadMissing('tenant');
        $procedure = $this->procedures->activeProcedure($job);

        $assignments = JobAssignment::with(['profile.user', 'checkins', 'checkouts', 'attendanceRecord', 'procedureAcknowledgements'])
            ->where('job_id', $job->id)
            ->whereIn('status', ['offered', 'accepted'])
            ->orderBy('id')
            ->get();

        $accepted = $assignments->where('status', 'accepted');

        $team = $assignments->map(fn (JobAssignment $assignment) => [
            'name' => $assignment->profile?->user?->name,
            'role' => $assignment->role,
            'status' => $assignment->status,
            'checked_in_at' => $assignment->checkins->sortByDesc('checked_in_at')->first()?->checked_in_at?->toIso8601String(),
            'checked_out_at' => $assignment->checkouts->sortByDesc('checked_out_at')->first()?->checked_out_at?->toIso8601String(),
            'attendance_status' => $assignment->attendanceRecord?->status,
        ])->values()->all();

        $readinessRows = $accepted->map(function (JobAssignment $assignment) use ($job, $procedure): array {
            $ack = $this->acknowledgement($assignment, $procedure);
            $checklists = $this->checklistPayloads($assignment, $procedure);
            $checkin = $assignment->checkins->sortByDesc('checked_in_at')->first();
            $checkout = $assignment->checkouts->sortByDesc('checked_out_at')->first();

            return $this->computeReadiness($assignment, $job, $procedure, $ack, $checklists, $checkin, $checkout);
        });

        $percent = $readinessRows->isEmpty() ? 0 : (int) round($readinessRows->avg('percent'));

        $events = JobProgressEvent::where('job_id', $job->id)
            ->orderBy('occurred_at')
            ->limit(100)
            ->get()
            ->map(fn (JobProgressEvent $event) => [
                'stage' => $event->stage,
                'percent' => (int) $event->percent,
                'message' => $event->message,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])->values()->all();

        return [
            'job' => $this->jobSummary($job),
            'procedure' => $procedure ? [
                'title' => $procedure->title,
                'version' => (int) $procedure->version,
                'summary' => $procedure->summary,
                'steps' => $procedure->procedureSteps->map(fn ($step) => [
                    'position' => (int) $step->position,
                    'title' => $step->title,
                    'instructions' => $step->instructions,
                ])->values()->all(),
            ] : null,
            'team' => $team,
            'attendance' => JobAttendance::where('job_id', $job->id)
                ->get()
                ->map(fn (JobAttendance $attendance) => [
                    'status' => $attendance->status,
                    'checked_in_at' => $attendance->checked_in_at?->toIso8601String(),
                    'checked_out_at' => $attendance->checked_out_at?->toIso8601String(),
                    'minutes_on_site' => $attendance->minutes_on_site,
                ])->values()->all(),
            'summary' => [
                'team_required' => (int) $job->required_team_size,
                'team_accepted' => $accepted->count(),
                'sop_assigned' => $procedure !== null,
                'sop_acknowledged' => $readinessRows->where('sop_acknowledged', true)->count(),
                'percent' => $percent,
                'stage' => $this->jobStage($job, $readinessRows->all()),
            ],
            'progress' => [
                'percent' => $percent,
                'stage' => $this->jobStage($job, $readinessRows->all()),
                'events' => $events,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $checklists
     * @return array{accepted: bool, has_procedure: bool, sop_required: bool, sop_acknowledged: bool, checklist_required: int, checklist_answered: int, checklist_complete: bool, checked_in: bool, count_in_progress: bool, can_check_in: bool, can_start_count: bool, percent: int, stage: string, blockers: array<int, string>}
     */
    private function computeReadiness(
        JobAssignment $assignment,
        StocktakeJob $job,
        ?JobProcedure $procedure,
        ?JobProcedureAcknowledgement $ack,
        array $checklists,
        ?JobSiteCheckin $checkin,
        ?JobSiteCheckout $checkout,
    ): array {
        $accepted = $assignment->status === 'accepted';
        $hasProcedure = $procedure !== null;
        $sopRequired = $hasProcedure && (bool) $procedure->require_acknowledgement;
        $sopAcknowledged = $sopRequired ? $ack !== null : $hasProcedure;
        $progress = $this->checklistProgress($checklists);
        $checkedIn = $checkin !== null && ($checkout === null
            || ($checkin->checked_in_at !== null && $checkout->checked_out_at !== null
                && $checkin->checked_in_at->greaterThan($checkout->checked_out_at)));
        $countInProgress = $job->status === 'in_progress';
        $jobReadyForFieldWork = in_array($job->status, ['scheduled', 'in_progress'], true);
        $prerequisitesComplete = $accepted && $hasProcedure && (! $sopRequired || $sopAcknowledged) && $progress['complete'];

        $blockers = [];
        if (! $accepted) {
            $blockers[] = 'Accept the assignment offer before starting work.';
        }
        if (! $hasProcedure) {
            $blockers[] = 'A job SOP has not been assigned.';
        } elseif ($sopRequired && $ack === null) {
            $blockers[] = 'Acknowledge the job SOP.';
        }
        if ($progress['answered'] < $progress['required']) {
            $blockers[] = 'Complete '.($progress['required'] - $progress['answered']).' required checklist item(s).';
        }
        if (! $checkedIn) {
            $blockers[] = 'Check in at the job site.';
        }
        if (! in_array($job->status, ['scheduled', 'in_progress'], true)) {
            $blockers[] = 'The job is not ready for field work ('.(StocktakeWorkflow::LABELS[$job->status] ?? $job->status).').';
        }
        $percent = 0;
        if ($accepted) {
            $percent += 10;
        }
        if ($hasProcedure && (! $sopRequired || $sopAcknowledged)) {
            $percent += 15;
        }
        if ($hasProcedure && $progress['complete']) {
            $percent += 25;
        }
        if ($checkedIn) {
            $percent += 25;
        }
        if ($countInProgress) {
            $percent += 25;
        }

        $stage = match (true) {
            ! $accepted => 'awaiting_acceptance',
            ! $hasProcedure => 'awaiting_sop',
            $sopRequired && $ack === null => 'awaiting_acknowledgement',
            ! $progress['complete'] => 'awaiting_checklist',
            ! $checkedIn => 'awaiting_check_in',
            $countInProgress => 'counting',
            default => 'ready',
        };

        return [
            'accepted' => $accepted,
            'has_procedure' => $hasProcedure,
            'sop_required' => $sopRequired,
            'sop_acknowledged' => $sopAcknowledged,
            'checklist_required' => $progress['required'],
            'checklist_answered' => $progress['answered'],
            'checklist_complete' => $progress['complete'],
            'checked_in' => $checkedIn,
            'count_in_progress' => $countInProgress,
            'can_check_in' => $prerequisitesComplete && $jobReadyForFieldWork && ! $checkedIn,
            'can_start_count' => $accepted && $hasProcedure && (! $sopRequired || $sopAcknowledged)
                && $progress['complete'] && $checkedIn && $countInProgress,
            'percent' => $percent,
            'stage' => $stage,
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function checklistPayloads(JobAssignment $assignment, ?JobProcedure $procedure): array
    {
        if ($procedure === null) {
            return [];
        }

        return JobChecklist::query()
            ->with(['items' => fn ($query) => $query->orderBy('position')])
            ->with(['items.responses' => fn ($query) => $query->where('assignment_id', $assignment->id)])
            ->where('job_id', $assignment->job_id)
            ->where('job_procedure_id', $procedure->id)
            ->orderBy('id')
            ->get()
            ->map(function (JobChecklist $checklist): array {
                $items = $checklist->items->map(function (JobChecklistItem $item): array {
                    $response = $item->responses->first();

                    return [
                        'id' => $item->uuid,
                        'label' => $item->label,
                        'instructions' => $item->instructions,
                        'response_type' => $item->response_type,
                        'required' => (bool) $item->required,
                        'options' => $item->options ?? [],
                        'response' => $response ? [
                            'value' => $response->value,
                            'notes' => $response->notes,
                            'responded_at' => $response->responded_at?->toIso8601String(),
                        ] : null,
                    ];
                })->values()->all();

                return [
                    'id' => $checklist->uuid,
                    'name' => $checklist->name,
                    'kind' => $checklist->kind,
                    'required' => (bool) $checklist->required,
                    'status' => $checklist->status,
                    'items' => $items,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $checklists
     * @return array{required: int, answered: int, complete: bool}
     */
    private function checklistProgress(array $checklists): array
    {
        $required = 0;
        $answered = 0;

        foreach ($checklists as $checklist) {
            if (! ($checklist['required'] ?? true)) {
                continue;
            }

            foreach ($checklist['items'] as $item) {
                if (! ($item['required'] ?? true)) {
                    continue;
                }

                $required++;
                if ($item['response'] !== null && trim((string) ($item['response']['value'] ?? '')) !== '') {
                    $answered++;
                }
            }
        }

        return [
            'required' => $required,
            'answered' => $answered,
            'complete' => $answered >= $required,
        ];
    }

    private function normalizeResponse(JobChecklistItem $item, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw ValidationException::withMessages(['value' => ['A response value is required.']]);
        }

        if ($item->response_type === 'boolean') {
            $normalized = match (Str::lower($value)) {
                'yes', 'true', '1', 'y' => 'yes',
                'no', 'false', '0', 'n' => 'no',
                default => null,
            };

            if ($normalized === null) {
                throw ValidationException::withMessages(['value' => ['Answer yes or no for this checklist item.']]);
            }

            return $normalized;
        }

        if ($item->response_type === 'select') {
            $options = array_map(fn ($option) => (string) $option, $item->options ?? []);
            if ($options !== [] && ! in_array($value, $options, true)) {
                throw ValidationException::withMessages(['value' => ['Choose one of the allowed options.']]);
            }
        }

        return Str::limit($value, 990, '');
    }

    /**
     * @return array<string, mixed>
     */
    private function progressForAssignment(JobAssignment $assignment): array
    {
        $events = JobProgressEvent::where('job_id', $assignment->job_id)
            ->where(function ($query) use ($assignment): void {
                $query->whereNull('assignment_id')->orWhere('assignment_id', $assignment->id);
            })
            ->orderBy('occurred_at')
            ->limit(100)
            ->get()
            ->map(fn (JobProgressEvent $event) => [
                'stage' => $event->stage,
                'percent' => (int) $event->percent,
                'message' => $event->message,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])->values()->all();

        return [
            'events' => $events,
            'latest_percent' => $events === [] ? 0 : (int) max(array_column($events, 'percent')),
        ];
    }

    private function acknowledgement(JobAssignment $assignment, ?JobProcedure $procedure): ?JobProcedureAcknowledgement
    {
        if ($procedure === null) {
            return null;
        }

        if ($assignment->relationLoaded('procedureAcknowledgements')) {
            return $assignment->procedureAcknowledgements->firstWhere('job_procedure_id', $procedure->id);
        }

        return JobProcedureAcknowledgement::where('job_procedure_id', $procedure->id)
            ->where('assignment_id', $assignment->id)
            ->first();
    }

    private function openCheckin(JobAssignment $assignment): ?JobSiteCheckin
    {
        $checkin = JobSiteCheckin::where('assignment_id', $assignment->id)->latest('checked_in_at')->first();
        if ($checkin === null) {
            return null;
        }

        $checkout = JobSiteCheckout::where('assignment_id', $assignment->id)->latest('checked_out_at')->first();

        return $checkout === null || $checkin->checked_in_at->greaterThan($checkout->checked_out_at) ? $checkin : null;
    }

    private function assertAccepted(JobAssignment $assignment): void
    {
        if ($assignment->status !== 'accepted') {
            throw ValidationException::withMessages(['assignment' => ['Accept the assignment offer before starting work.']]);
        }
    }

    private function assertOwnedBy(JobAssignment $assignment, User $worker): void
    {
        $assignment->loadMissing('profile');
        abort_unless($assignment->profile?->user_id === $worker->id, 404);
    }

    /**
     * @param  array<int, array<string, mixed>>  $readinessRows
     */
    private function jobStage(StocktakeJob $job, array $readinessRows): string
    {
        if (in_array($job->status, ['in_progress', 'paused'], true)) {
            return 'counting';
        }

        if ($job->status === 'scheduled' && $readinessRows !== []) {
            $allReady = collect($readinessRows)->every(fn (array $row) => $row['checked_in']);

            return $allReady ? 'counting' : (collect($readinessRows)->first()['stage'] ?? 'awaiting_check_in');
        }

        if ($readinessRows !== []) {
            return collect($readinessRows)->first()['stage'];
        }

        return $job->status === 'awaiting_assignment' || $job->status === 'approved_for_execution'
            ? 'awaiting_assignment'
            : 'awaiting_acceptance';
    }

    /**
     * @return array<string, mixed>
     */
    private function jobSummary(StocktakeJob $job): array
    {
        return [
            'id' => $job->uuid,
            'reference' => $job->reference,
            'title' => $job->title,
            'status' => $job->status,
            'status_label' => StocktakeWorkflow::LABELS[$job->status] ?? ucfirst(str_replace('_', ' ', $job->status)),
            'priority' => $job->priority,
            'scope_label' => $job->scope['label'] ?? null,
            'tenant' => $job->tenant?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkinPayload(JobSiteCheckin $checkin): array
    {
        return [
            'id' => $checkin->uuid,
            'site_label' => $checkin->site_label,
            'latitude' => $checkin->latitude,
            'longitude' => $checkin->longitude,
            'method' => $checkin->method,
            'checked_in_at' => $checkin->checked_in_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutPayload(JobSiteCheckout $checkout): array
    {
        return [
            'id' => $checkout->uuid,
            'notes' => $checkout->notes,
            'checked_out_at' => $checkout->checked_out_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attendancePayload(JobAttendance $attendance): array
    {
        return [
            'id' => $attendance->uuid,
            'status' => $attendance->status,
            'scheduled_start_at' => $attendance->scheduled_start_at?->toIso8601String(),
            'checked_in_at' => $attendance->checked_in_at?->toIso8601String(),
            'checked_out_at' => $attendance->checked_out_at?->toIso8601String(),
            'minutes_on_site' => $attendance->minutes_on_site,
        ];
    }

    private function recordProgress(
        StocktakeJob $job,
        ?JobAssignment $assignment,
        string $stage,
        int $percent,
        ?string $message,
        User $actor,
        string $actorType,
    ): JobProgressEvent {
        return JobProgressEvent::create([
            'tenant_id' => $job->tenant_id,
            'job_id' => $job->id,
            'assignment_id' => $assignment?->id,
            'stage' => $stage,
            'percent' => $percent,
            'message' => $message,
            'actor_id' => $actor->id,
            'actor_type' => $actorType,
            'occurred_at' => now(),
        ]);
    }
}
