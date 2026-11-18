<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Http\Resources\UserResource;
use App\Mail\UserInvitationMail;
use App\Models\Branch;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Email\TransactionalEmailService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class InvitationController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCan($request, $tenant, 'tenant.users.manage');

        $invitations = $tenant->invitations()
            ->with(['role', 'branch'])
            ->latest()
            ->get()
            ->map(fn (Invitation $invitation) => $this->payload($invitation, $tenant));

        return ApiResponse::success($invitations);
    }

    public function store(Request $request, Tenant $tenant, AuditLogger $audit, TransactionalEmailService $emails): JsonResponse
    {
        $this->assertCan($request, $tenant, 'tenant.users.manage');

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::exists('roles', 'slug')->where('scope', 'tenant')],
            'branch_id' => ['nullable', 'string'],
        ]);

        $email = Str::lower($data['email']);
        abort_if(
            $tenant->memberships()->whereHas('user', fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email]))->where('status', 'active')->exists(),
            422,
            'This person is already a member of the organisation.'
        );
        abort_if(
            $tenant->invitations()->where('email', $email)->where('status', 'pending')->where('expires_at', '>', now())->exists(),
            422,
            'A pending invitation already exists for this email.'
        );

        $branch = $this->resolveBranch($tenant, $data['branch_id'] ?? null);
        $role = Role::where('slug', $data['role'])->where('scope', 'tenant')->firstOrFail();
        $token = Str::random(48);

        $invitation = $tenant->invitations()->create([
            'email' => $email,
            'role_id' => $role->id,
            'branch_id' => $branch?->id,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $request->user()->id,
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        $acceptUrl = rtrim((string) config('app.url'), '/').'/invitations/accept?token='.$token;
        $emails->queue(
            new UserInvitationMail(
                $invitation->email,
                $tenant->name,
                $role->name,
                $acceptUrl,
                $invitation->expires_at->toIso8601String(),
            ),
            $invitation->email,
            'tenant.invitation',
            [
                'recipient_email' => $invitation->email,
                'organisation_name' => $tenant->name,
                'role_name' => $role->name,
                'accept_url' => $acceptUrl,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
            $invitation->expires_at,
        );

        $audit->log('invitation.created', $invitation, null, null, [
            'email' => $invitation->email,
            'role' => $role->slug,
            'branch_id' => $branch?->uuid,
        ], $tenant->id);

        return ApiResponse::success([
            ...$this->payload($invitation->load(['role', 'branch']), $tenant),
            'sent' => true,
        ], 201);
    }

    public function preview(Request $request, string $token): JsonResponse
    {
        $invitation = Invitation::with(['tenant', 'role', 'branch'])->where('token_hash', hash('sha256', $token))->first();
        abort_if($invitation === null, 404, 'Invitation not found.');

        return ApiResponse::success([
            'invitation_id' => $invitation->uuid,
            'organisation' => $invitation->tenant?->name,
            'email' => $invitation->email,
            'role' => $invitation->role?->name,
            'branch' => $invitation->branch?->name,
            'status' => $invitation->status === 'pending' && $invitation->expires_at->isPast()
                ? 'expired'
                : $invitation->status,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
        ]);
    }

    public function accept(Request $request, string $token, AuditLogger $audit): JsonResponse
    {
        $invitation = Invitation::with(['tenant', 'role', 'branch'])->where('token_hash', hash('sha256', $token))->first();
        abort_if($invitation === null, 404, 'Invitation not found.');

        if ($invitation->status !== 'pending') {
            abort(422, 'This invitation has already been used or revoked.');
        }

        if ($invitation->expires_at->isPast()) {
            $invitation->update(['status' => 'expired']);

            abort(422, 'This invitation has expired. Ask an administrator to send a new one.');
        }

        /** @var User $user */
        $user = $request->user();
        abort_unless(Str::lower($user->email) === Str::lower($invitation->email), 403, 'This invitation was sent to a different email address.');

        $tenant = $invitation->tenant;

        DB::transaction(function () use ($invitation, $tenant, $user): void {
            $tenant->memberships()->updateOrCreate(
                ['user_id' => $user->id],
                ['status' => 'active', 'joined_at' => now()],
            );

            if ($invitation->role_id !== null) {
                $user->roles()->syncWithoutDetaching([$invitation->role_id => ['tenant_id' => $tenant->id]]);
            }

            if ($invitation->branch_id !== null) {
                DB::table('user_branch_scopes')->insertOrIgnore([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'branch_id' => $invitation->branch_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $invitation->update([
                'status' => 'accepted',
                'accepted_at' => now(),
                'accepted_by' => $user->id,
            ]);
        });

        $audit->log('invitation.accepted', $invitation, null, null, [
            'email' => $invitation->email,
            'tenant' => $tenant->name,
        ], $tenant->id);

        return ApiResponse::success(new TenantResource($tenant->loadCount(['members', 'branches'])));
    }

    public function activate(Request $request, string $token, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()->mixedCase()->symbols()],
        ]);

        $result = DB::transaction(function () use ($token, $data): array {
            $invitation = Invitation::with(['tenant', 'role', 'branch'])
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();
            abort_if($invitation === null, 404, 'Invitation not found.');

            if ($invitation->status !== 'pending') {
                abort(422, 'This invitation has already been used or revoked.');
            }
            if ($invitation->expires_at->isPast()) {
                $invitation->update(['status' => 'expired']);

                return ['expired' => true];
            }

            if (User::whereRaw('LOWER(email) = ?', [strtolower($invitation->email)])->exists()) {
                return ['existing_user' => true];
            }

            $tenant = $invitation->tenant;
            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'email' => strtolower($invitation->email),
                'password' => $data['password'],
                'email_verified_at' => now(),
                'must_change_password' => false,
            ]);
            $user->save();

            $tenant->memberships()->updateOrCreate(
                ['user_id' => $user->id],
                ['status' => 'active', 'joined_at' => now()],
            );

            if ($invitation->role_id !== null) {
                $user->roles()->syncWithoutDetaching([$invitation->role_id => ['tenant_id' => $tenant->id]]);
            }
            if ($invitation->branch_id !== null) {
                DB::table('user_branch_scopes')->insertOrIgnore([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'branch_id' => $invitation->branch_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $invitation->forceFill([
                'status' => 'accepted',
                'accepted_at' => now(),
                'accepted_by' => $user->id,
            ])->save();

            return ['expired' => false, 'existing_user' => false, 'invitation' => $invitation, 'tenant' => $tenant, 'user' => $user];
        });

        if ($result['expired'] ?? false) {
            abort(422, 'This invitation has expired.');
        }
        if ($result['existing_user'] ?? false) {
            abort(409, 'An account already exists for this email. Sign in and reopen the invitation to accept it.');
        }

        /** @var Invitation $invitation */
        $invitation = $result['invitation'];
        /** @var Tenant $tenant */
        $tenant = $result['tenant'];
        /** @var User $user */
        $user = $result['user'];

        $audit->log('invitation.accepted', $invitation, null, null, [
            'email' => $invitation->email,
            'tenant' => $tenant->name,
        ], $tenant->id);

        $token = $user->createToken('invitation-activation')->plainTextToken;

        return ApiResponse::success([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    public function resend(
        Request $request,
        Tenant $tenant,
        Invitation $invitation,
        AuditLogger $audit,
        TransactionalEmailService $emails,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'tenant.users.manage');
        $this->assertBelongsToTenant($tenant, $invitation);

        abort_unless($invitation->status === 'pending', 422, 'Only pending invitations can be resent.');
        if ($invitation->last_sent_at !== null && $invitation->last_sent_at->diffInSeconds(now()) < 60) {
            abort(422, 'Please wait before resending this invitation.');
        }
        if ($invitation->resend_count >= 5) {
            abort(422, 'The maximum resend limit has been reached.');
        }

        $invitation->forceFill([
            'last_sent_at' => now(),
            'resend_count' => $invitation->resend_count + 1,
        ])->save();

        $token = Str::random(48);
        $invitation->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ])->save();

        $acceptUrl = rtrim((string) config('app.url'), '/').'/invitations/accept?token='.$token;
        $roleName = $invitation->role?->name ?: 'Team member';
        $emails->queue(
            new UserInvitationMail(
                $invitation->email,
                $tenant->name,
                $roleName,
                $acceptUrl,
                $invitation->expires_at->toIso8601String(),
            ),
            $invitation->email,
            'tenant.invitation',
            [
                'recipient_email' => $invitation->email,
                'organisation_name' => $tenant->name,
                'role_name' => $roleName,
                'accept_url' => $acceptUrl,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
            $invitation->expires_at,
        );

        $audit->log('invitation.resent', $invitation, null, null, ['email' => $invitation->email], $tenant->id);

        return ApiResponse::success(['sent' => true, 'status' => 'pending']);
    }

    public function revoke(Request $request, Tenant $tenant, Invitation $invitation, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'tenant.users.manage');
        $this->assertBelongsToTenant($tenant, $invitation);

        abort_unless($invitation->status === 'pending', 422, 'Only pending invitations can be revoked.');

        $invitation->update(['status' => 'revoked', 'revoked_at' => now()]);
        $audit->log('invitation.revoked', $invitation, null, null, ['email' => $invitation->email], $tenant->id);

        return ApiResponse::success(['status' => 'revoked']);
    }

    private function payload(Invitation $invitation, Tenant $tenant): array
    {
        return [
            'id' => $invitation->uuid,
            'email' => $invitation->email,
            'role' => $invitation->role?->slug,
            'role_name' => $invitation->role?->name,
            'branch_id' => $invitation->branch?->uuid,
            'branch_name' => $invitation->branch?->name,
            'status' => $invitation->isPending() ? 'pending' : ($invitation->expires_at?->isPast() && $invitation->status === 'pending' ? 'expired' : $invitation->status),
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
        ];
    }

    private function resolveBranch(Tenant $tenant, ?string $uuid): ?Branch
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $branch = Branch::where('tenant_id', $tenant->id)->where('uuid', $uuid)->first();
        abort_if($branch === null, 422, 'The selected branch does not belong to this organisation.');

        return $branch;
    }
}
