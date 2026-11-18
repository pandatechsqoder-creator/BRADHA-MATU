<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\JobAssignment;
use App\Models\StocktakeJob;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\TenantContext;
use App\Services\Workforce\AssignmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AssignmentController extends Controller
{
    public function recommendations(
        string $job,
        AssignmentService $assignments,
        TenantContext $context,
    ): JsonResponse {
        $model = $this->findJob($job, $context);

        return ApiResponse::success([
            ...$assignments->recommendations($model),
            'assignments' => $this->assignmentRows($model, $assignments),
        ]);
    }

    public function index(
        string $job,
        AssignmentService $assignments,
        TenantContext $context,
    ): JsonResponse {
        $model = $this->findJob($job, $context);

        return ApiResponse::success([
            'job' => [
                'id' => $model->uuid,
                'reference' => $model->reference,
                'status' => $model->status,
                'required_team_size' => $model->required_team_size,
            ],
            'assignments' => $this->assignmentRows($model, $assignments),
        ]);
    }

    public function store(
        string $job,
        Request $request,
        AssignmentService $assignments,
        TenantContext $context,
        AuditLogger $audit,
    ): JsonResponse {
        $model = $this->findJob($job, $context);
        $data = $request->validate($this->rules());

        $assignment = $assignments->offer($model, $data, $request->user());
        $audit->log('workforce.assignment.offered', $assignment, $data['override_reason'] ?? null, null, [
            'job_id' => $model->uuid,
            'profile_id' => $data['profile_id'],
            'compensation' => (float) $assignment->compensation_amount,
            'override' => ! empty($data['override_reason']),
        ], $model->tenant_id);

        return ApiResponse::success([
            'assignment' => $assignments->platformPayload($assignment),
            ...$this->currentState($model, $assignments),
        ], 201);
    }

    public function reassign(
        JobAssignment $assignment,
        Request $request,
        AssignmentService $assignments,
        TenantContext $context,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'profile_id' => ['required', 'string', 'max:60'],
            'role' => ['nullable', Rule::in(['lead', 'counter', 'supervisor'])],
            'compensation_amount' => ['nullable', 'numeric', 'min:0'],
            'expires_in_hours' => ['nullable', 'integer', 'between:1,168'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'override_reason' => ['nullable', 'string', 'min:5', 'max:500'],
        ]);

        $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($assignment->job_id)->firstOrFail();
        $context->set($job->tenant);

        $replacement = $assignments->reassign($assignment, $data, $request->user());
        $audit->log('workforce.assignment.reassigned', $replacement, $data['reason'], [
            'assignment_id' => $assignment->uuid,
            'profile_id' => $assignment->profile_id,
        ], [
            'assignment_id' => $replacement->uuid,
            'profile_id' => $replacement->profile_id,
            'override' => ! empty($data['override_reason']),
        ], $job->tenant_id);

        return ApiResponse::success([
            'assignment' => $assignments->platformPayload($replacement),
            ...$this->currentState($job, $assignments),
        ]);
    }

    public function withdraw(
        JobAssignment $assignment,
        Request $request,
        AssignmentService $assignments,
        TenantContext $context,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($assignment->job_id)->firstOrFail();
        $context->set($job->tenant);

        $withdrawn = $assignments->withdraw($assignment, $request->user(), $data['reason'] ?? null);
        $audit->log('workforce.assignment.withdrawn', $withdrawn, $data['reason'] ?? null, [
            'assignment_id' => $assignment->uuid,
            'profile_id' => $assignment->profile_id,
        ], ['status' => 'withdrawn'], $job->tenant_id);

        return ApiResponse::success([
            'assignment' => $assignments->platformPayload($withdrawn),
            ...$this->currentState($job, $assignments),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentState(StocktakeJob $job, AssignmentService $assignments): array
    {
        $job = $job->fresh();

        return [
            'job' => [
                'id' => $job->uuid,
                'reference' => $job->reference,
                'status' => $job->status,
                'required_team_size' => $job->required_team_size,
            ],
            'assignments' => $this->assignmentRows($job, $assignments),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function assignmentRows(StocktakeJob $job, AssignmentService $assignments): array
    {
        return $job->assignments()
            ->with(['job.tenant', 'profile.user', 'events', 'earnings'])
            ->whereIn('status', ['offered', 'accepted', 'declined', 'expired', 'withdrawn', 'reassigned'])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (JobAssignment $assignment) => $assignments->platformPayload($assignment))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'profile_id' => ['required', 'string', 'max:60'],
            'role' => ['nullable', Rule::in(['lead', 'counter', 'supervisor'])],
            'compensation_amount' => ['nullable', 'numeric', 'min:0'],
            'expires_in_hours' => ['nullable', 'integer', 'between:1,168'],
            'override_reason' => ['nullable', 'string', 'min:5', 'max:500'],
        ];
    }

    private function findJob(string $uuid, TenantContext $context): StocktakeJob
    {
        $job = StocktakeJob::withoutGlobalScope('tenant')->where('uuid', $uuid)->firstOrFail();
        $context->set($job->tenant);

        return $job;
    }
}
