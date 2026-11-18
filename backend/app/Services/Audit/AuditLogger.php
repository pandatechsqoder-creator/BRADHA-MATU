<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class AuditLogger
{
    public function __construct(
        private readonly Request $request,
        private readonly TenantContext $tenantContext,
    ) {}

    public function log(
        string $action,
        ?Model $subject = null,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null,
        ?int $tenantId = null,
        ?int $actorId = null,
    ): AuditLog {
        return AuditLog::create([
            'tenant_id' => $tenantId ?? $this->tenantContext->id(),
            'user_id' => $actorId ?? Auth::id(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'reason' => $reason,
            'before' => $before,
            'after' => $after,
            'request_id' => $this->request->attributes->get('request_id'),
            'ip' => $this->request->ip(),
        ]);
    }
}