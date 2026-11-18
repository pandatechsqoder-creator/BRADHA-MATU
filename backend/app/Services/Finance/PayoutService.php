<?php

namespace App\Services\Finance;

use App\Models\JobAssignment;
use App\Models\PayoutApproval;
use App\Models\PayoutBatch;
use App\Models\StockTakerEarning;
use App\Models\StockTakerPayout;
use App\Models\StocktakeJob;
use App\Models\StockTakerProfile;
use App\Models\User;
use App\Services\Stocktake\StocktakeWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Payout queue with maker-checker approval.
 *
 * Maker (finance-officer) prepares a batch of payouts from approved earnings.
 * Checker (finance-manager) approves the batch before processing begins.
 * Maker ≠ Checker — the same user cannot both prepare and approve a batch.
 * No payout can be issued before the job reaches completion_confirmed.
 */
final class PayoutService
{
    public function __construct(private readonly StocktakeWorkflow $workflow) {}

    /**
     * Queue a payout for a completed job. Can be called per-job or per-batch.
     * Creates individual StockTakerPayout records for each approved assignment.
     * Maker step — does NOT require the approver.
     *
     * @return array<string, mixed>
     */
    public function prepareBatch(StocktakeJob $job, User $preparer, string $method = 'mpesa'): array
    {
        if (! in_array($job->status, ['completion_confirmed', 'payout_pending_authorisation'], true)) {
            throw ValidationException::withMessages(['job' => ['Payouts can only be prepared after the job is completion confirmed.']]);
        }

        return DB::transaction(function () use ($job, $preparer, $method): array {
            // Find all approved assignments with accrued earnings
            $earnings = StockTakerEarning::query()
                ->whereHas('assignment', fn ($q) => $q->where('job_id', $job->id))
                ->where('status', 'accrued')
                ->with(['assignment.profile', 'profile'])
                ->get();

            if ($earnings->isEmpty()) {
                throw ValidationException::withMessages(['payout' => ['No accrued earnings found for this job.']]);
            }

            $total = $earnings->sum('amount');
            $ref = 'PAY-'.strtoupper(Str::ulid()->toBase32());

            $batch = PayoutBatch::create([
                'reference' => $ref,
                'status' => 'pending_approval',
                'total_amount' => $total,
                'currency' => 'KES',
                'payout_count' => $earnings->count(),
                'prepared_by' => $preparer->id,
                'prepared_at' => now(),
            ]);

            foreach ($earnings as $earning) {
                $profile = $earning->profile ?? StockTakerProfile::whereKey($earning->profile_id)->first();

                StockTakerPayout::create([
                    'profile_id' => $earning->profile_id,
                    'job_id' => $job->id,
                    'assignment_id' => $earning->assignment_id,
                    'earning_id' => $earning->id,
                    'batch_id' => $batch->id,
                    'amount' => $earning->amount,
                    'currency' => 'KES',
                    'method' => $method,
                    'recipient_identifier' => $profile?->phone,
                    'status' => 'pending',
                ]);
            }

            if ($job->status === 'completion_confirmed') {
                $this->workflow->transition($job, 'payout_pending_authorisation', $preparer, 'Payout batch '.$ref.' prepared.');
            }

            return $this->batchPayload($batch->fresh()->loadMissing('payouts'));
        });
    }

    /**
     * Approve a batch (Checker step). Maker ≠ Checker enforced here.
     *
     * @return array<string, mixed>
     */
    public function approveBatch(PayoutBatch $batch, User $approver, ?string $notes = null): array
    {
        if ($batch->status !== 'pending_approval') {
            throw ValidationException::withMessages(['batch' => ['Batch is not awaiting approval.']]);
        }

        // Maker-checker: approver must differ from preparer
        if ($batch->prepared_by === $approver->id) {
            throw ValidationException::withMessages(['approver' => ['The approver must be different from the preparer (maker-checker rule).']]);
        }

        return DB::transaction(function () use ($batch, $approver, $notes): array {
            PayoutApproval::create([
                'batch_id' => $batch->id,
                'approver_id' => $approver->id,
                'decision' => 'approve',
                'notes' => $notes,
            ]);

            $batch->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'notes' => $notes,
            ]);

            return $this->batchPayload($batch->fresh()->loadMissing('payouts'));
        });
    }

    /**
     * Process a batch — trigger the payout adapter for each pending payout.
     * In sandbox mode: marks all payouts as completed immediately (sandbox adapter).
     * In production: would call M-Pesa/bank API and await callbacks.
     *
     * @return array<string, mixed>
     */
    public function processBatch(PayoutBatch $batch, User $processor): array
    {
        if ($batch->status !== 'approved') {
            throw ValidationException::withMessages(['batch' => ['Batch must be approved before processing.']]);
        }

        return DB::transaction(function () use ($batch, $processor): array {
            $batch->update(['status' => 'processing', 'processed_at' => now()]);

            // Process each payout through the sandbox adapter
            $allCompleted = true;
            foreach ($batch->payouts()->where('status', 'pending')->get() as $payout) {
                $result = $this->sandboxProcess($payout, $processor);
                if ($result['status'] !== 'completed') {
                    $allCompleted = false;
                }
            }

            if ($allCompleted) {
                $batch->update(['status' => 'completed', 'completed_at' => now()]);

                // Mark all earnings as paid
                StockTakerEarning::query()
                    ->whereIn('id', $batch->payouts()->pluck('earning_id')->filter()->all())
                    ->update(['status' => 'paid']);
            }

            // Transition the job
            $job = StocktakeJob::withoutGlobalScope('tenant')
                ->whereKey($batch->payouts()->value('job_id'))
                ->first();

            if ($job && $job->status === 'payout_pending_authorisation') {
                $this->workflow->transition($job, 'payout_processing', $processor, 'Payout batch '.$batch->reference.' processing.');
                if ($allCompleted) {
                    $this->workflow->transition($job->fresh(), 'payout_completed', $processor, 'Payout batch '.$batch->reference.' completed.');
                }
            }

            return $this->batchPayload($batch->fresh()->loadMissing('payouts'));
        });
    }

    /**
     * Close a job after payout completes.
     *
     * @return array<string, mixed>
     */
    public function closeJob(StocktakeJob $job, User $user): array
    {
        if ($job->status !== 'payout_completed') {
            throw ValidationException::withMessages(['job' => ['Job can only be closed after payout is completed.']]);
        }

        $this->workflow->transition($job, 'closed', $user, 'Job closed after payout completion.');

        return ['status' => 'closed', 'closed_at' => now()->toIso8601String()];
    }

    /**
     * List pending payout batches for the finance dashboard.
     *
     * @return array<string, mixed>[]
     */
    public function pendingBatches(): array
    {
        return PayoutBatch::with(['payouts', 'preparedBy', 'approvedBy'])
            ->whereIn('status', ['pending_approval', 'approved', 'processing'])
            ->orderByDesc('prepared_at')
            ->get()
            ->map(fn (PayoutBatch $b) => $this->batchPayload($b))
            ->values()
            ->all();
    }

    /**
     * Full batch payload.
     *
     * @return array<string, mixed>
     */
    public function batchPayload(PayoutBatch $batch): array
    {
        return [
            'id' => $batch->uuid,
            'reference' => $batch->reference,
            'status' => $batch->status,
            'total_amount' => $batch->total_amount,
            'currency' => $batch->currency,
            'payout_count' => $batch->payout_count,
            'prepared_at' => $batch->prepared_at?->toIso8601String(),
            'approved_at' => $batch->approved_at?->toIso8601String(),
            'completed_at' => $batch->completed_at?->toIso8601String(),
            'payouts' => $batch->relationLoaded('payouts') ? $batch->payouts->map(fn (StockTakerPayout $p) => [
                'id' => $p->uuid,
                'profile_id' => $p->profile?->uuid ?? $p->profile_id,
                'amount' => $p->amount,
                'method' => $p->method,
                'recipient' => $p->recipient_identifier,
                'status' => $p->status,
                'completed_at' => $p->completed_at?->toIso8601String(),
            ])->values()->all() : [],
        ];
    }

    /**
     * Sandbox payout processor — immediately marks as completed.
     * In production this would call M-Pesa/bank APIs.
     *
     * @return array<string, mixed>
     */
    private function sandboxProcess(StockTakerPayout $payout, User $actor): array
    {
        $ref = 'SANDBOX-'.strtoupper(Str::ulid()->toBase32());

        $payout->update([
            'status' => 'completed',
            'provider_reference' => $ref,
            'provider_status' => 'completed',
            'processed_by' => $actor->id,
            'processed_at' => now(),
            'completed_at' => now(),
        ]);

        return ['status' => 'completed', 'reference' => $ref];
    }
}
