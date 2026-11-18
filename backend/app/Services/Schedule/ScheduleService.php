<?php

namespace App\Services\Schedule;

use App\Models\ScheduleOccurrence;
use App\Models\StocktakeSchedule;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recurring and on-demand stocktake schedule management.
 *
 * Key invariant: no paid job or charge is ever created automatically.
 * - 'reminder' mode: only records an occurrence, no job created.
 * - 'proposed' mode: creates a draft job requiring explicit tenant authorisation.
 * - 'prepaid' mode: same as proposed but references a prepaid agreement (Phase 20+).
 *
 * No duplicate occurrences: the unique constraint on (schedule_id, scheduled_at) prevents
 * the same date appearing twice regardless of how many times the generator runs.
 */
final class ScheduleService
{
    private const FREQUENCIES = ['on_demand', 'weekly', 'fortnightly', 'monthly', 'quarterly', 'half_yearly', 'annual', 'custom'];

    /** @return array<string, mixed> */
    public function create(Tenant $tenant, array $data, User $user): array
    {
        $data = validator($data, [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'mode' => ['required', 'string', 'in:reminder,proposed,prepaid'],
            'frequency' => ['required', 'string', 'in:'.implode(',', self::FREQUENCIES)],
            'scope_type' => ['nullable', 'string', 'in:all,category,branch,custom'],
            'scope_config' => ['nullable', 'array'],
            'recurrence_config' => ['nullable', 'array'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ])->validate();

        $schedule = StocktakeSchedule::create([
            'tenant_id' => $tenant->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'mode' => $data['mode'],
            'frequency' => $data['frequency'],
            'scope_type' => $data['scope_type'] ?? 'all',
            'scope_config' => $data['scope_config'] ?? null,
            'recurrence_config' => $data['recurrence_config'] ?? null,
            'active' => true,
            'starts_at' => isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : null,
            'ends_at' => isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : null,
            'created_by' => $user->id,
        ]);

        $schedule->update(['next_occurrence_at' => $this->computeNext($schedule)]);

        return $this->payload($schedule);
    }

    /** @return array<string, mixed> */
    public function update(StocktakeSchedule $schedule, array $data, User $user): array
    {
        $data = validator($data, [
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'mode' => ['sometimes', 'string', 'in:reminder,proposed,prepaid'],
            'frequency' => ['sometimes', 'string', 'in:'.implode(',', self::FREQUENCIES)],
            'active' => ['sometimes', 'boolean'],
            'recurrence_config' => ['nullable', 'array'],
            'ends_at' => ['nullable', 'date'],
        ])->validate();

        $schedule->update($data);
        $schedule->update(['next_occurrence_at' => $this->computeNext($schedule->fresh())]);

        return $this->payload($schedule->fresh());
    }

    /** @return array<string, mixed>[] */
    public function list(Tenant $tenant): array
    {
        return StocktakeSchedule::where('tenant_id', $tenant->id)
            ->withCount('occurrences')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (StocktakeSchedule $s) => $this->payload($s))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function show(StocktakeSchedule $schedule): array
    {
        $schedule->loadMissing('occurrences');
        return $this->payload($schedule, withOccurrences: true);
    }

    /**
     * Generate upcoming occurrences for a schedule (up to $count).
     * Skips dates that already have an occurrence (no duplicates).
     * Does NOT create jobs — that requires explicit tenant action.
     *
     * @return array<string, mixed>
     */
    public function generateOccurrences(StocktakeSchedule $schedule, int $count = 3): array
    {
        if ($schedule->frequency === 'on_demand') {
            throw ValidationException::withMessages(['frequency' => ['On-demand schedules do not auto-generate occurrences.']]);
        }

        $dates = $this->nextDates($schedule, $count);
        $created = 0;

        DB::transaction(function () use ($schedule, $dates, &$created): void {
            foreach ($dates as $date) {
                $existing = ScheduleOccurrence::where('schedule_id', $schedule->id)
                    ->where('scheduled_at', $date->toDateTimeString())
                    ->exists();
                if ($existing) continue;

                ScheduleOccurrence::create([
                    'schedule_id' => $schedule->id,
                    'tenant_id' => $schedule->tenant_id,
                    'scheduled_at' => $date,
                    'status' => 'pending',
                    'created_by' => $schedule->created_by,
                ]);
                $created++;
            }

            if ($created > 0) {
                $schedule->update([
                    'next_occurrence_at' => $dates->last(),
                    'last_occurrence_at' => now(),
                ]);
            }
        });

        return ['generated' => $created, 'schedule' => $this->payload($schedule->fresh())];
    }

    /**
     * Trigger an on-demand occurrence (manual trigger by tenant).
     * Requires explicit authorisation — no charge is auto-created.
     *
     * @return array<string, mixed>
     */
    public function triggerOnDemand(StocktakeSchedule $schedule, User $user): array
    {
        $occurrence = ScheduleOccurrence::create([
            'schedule_id' => $schedule->id,
            'tenant_id' => $schedule->tenant_id,
            'scheduled_at' => now(),
            'status' => 'pending',
            'created_by' => $user->id,
        ]);

        return ['id' => $occurrence->uuid, 'status' => $occurrence->status, 'scheduled_at' => $occurrence->scheduled_at->toIso8601String()];
    }

    /**
     * Skip an occurrence (e.g. closed for stocktake, inventory stable).
     *
     * @return array<string, mixed>
     */
    public function skipOccurrence(ScheduleOccurrence $occurrence, string $reason, User $user): array
    {
        if ($occurrence->status !== 'pending') {
            throw ValidationException::withMessages(['occurrence' => ['Only pending occurrences can be skipped.']]);
        }

        $occurrence->update(['status' => 'skipped', 'skip_reason' => $reason]);
        return ['id' => $occurrence->uuid, 'status' => 'skipped'];
    }

    /**
     * Pause or resume a schedule.
     */
    public function setActive(StocktakeSchedule $schedule, bool $active): StocktakeSchedule
    {
        $schedule->update(['active' => $active]);
        return $schedule->fresh();
    }

    // ---- internals ----

    private function computeNext(StocktakeSchedule $schedule): ?Carbon
    {
        if (! $schedule->active || $schedule->frequency === 'on_demand') return null;
        $dates = $this->nextDates($schedule, 1);
        return $dates->first();
    }

    /** @return Collection<int, Carbon> */
    private function nextDates(StocktakeSchedule $schedule, int $count): Collection
    {
        $start = $schedule->starts_at ? max($schedule->starts_at, now()) : now();
        $end = $schedule->ends_at;
        $config = $schedule->recurrence_config ?? [];
        $results = collect();

        $current = $start->copy();

        while ($results->count() < $count) {
            $next = match ($schedule->frequency) {
                'weekly' => $current->copy()->addWeek()->startOfDay(),
                'fortnightly' => $current->copy()->addWeeks(2)->startOfDay(),
                'monthly' => $current->copy()->addMonth()->startOfDay()->day(
                    min($config['day_of_month'] ?? 1, $current->copy()->addMonth()->daysInMonth)
                ),
                'quarterly' => $current->copy()->addMonths(3)->startOfDay(),
                'half_yearly' => $current->copy()->addMonths(6)->startOfDay(),
                'annual' => $current->copy()->addYear()->startOfDay(),
                default => $current->copy()->addDay()->startOfDay(),
            };

            if ($end && $next->greaterThan($end)) break;
            $results->push($next);
            $current = $next;
        }

        return $results;
    }

    /** @return array<string, mixed> */
    private function payload(StocktakeSchedule $schedule, bool $withOccurrences = false): array
    {
        $base = [
            'id' => $schedule->uuid,
            'title' => $schedule->title,
            'description' => $schedule->description,
            'mode' => $schedule->mode,
            'frequency' => $schedule->frequency,
            'scope_type' => $schedule->scope_type,
            'active' => $schedule->active,
            'next_occurrence_at' => $schedule->next_occurrence_at?->toIso8601String(),
            'last_occurrence_at' => $schedule->last_occurrence_at?->toIso8601String(),
            'starts_at' => $schedule->starts_at?->toIso8601String(),
            'ends_at' => $schedule->ends_at?->toIso8601String(),
        ];

        if ($withOccurrences) {
            $base['occurrences'] = $schedule->occurrences->map(fn (ScheduleOccurrence $o) => [
                'id' => $o->uuid,
                'scheduled_at' => $o->scheduled_at->toIso8601String(),
                'status' => $o->status,
                'skip_reason' => $o->skip_reason,
            ])->values()->all();
        }

        return $base;
    }
}
