<?php

namespace App\Services\Identity;

use App\Mail\VerificationCodeMail;
use App\Models\User;
use App\Models\UserVerification;
use App\Services\Email\TransactionalEmailService;
use Illuminate\Support\Facades\Hash;

final class EmailVerification
{
    public function __construct(private readonly TransactionalEmailService $emails) {}

    public function issue(User $user): string
    {
        $code = (string) random_int(100000, 999999);

        UserVerification::query()
            ->where('user_id', $user->id)
            ->where('channel', 'email')
            ->where('status', 'pending')
            ->update(['status' => 'expired']);

        UserVerification::create([
            'user_id' => $user->id,
            'channel' => 'email',
            'status' => 'pending',
            'reference' => Hash::make($code),
        ]);

        if (! $this->testMode()) {
            $this->emails->queue(
                new VerificationCodeMail($user->name, $code),
                $user->email,
                'auth.verification',
                ['recipient_name' => $user->name, 'code' => $code],
                now()->addMinutes(15),
            );
        }

        return $code;
    }

    public function confirm(User $user, string $code): bool
    {
        $verification = UserVerification::query()
            ->where('user_id', $user->id)
            ->where('channel', 'email')
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if (! $verification || ! Hash::check($code, (string) $verification->reference)) {
            return false;
        }

        $verification->update(['status' => 'verified', 'verified_at' => now()]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return true;
    }

    public function testMode(): bool
    {
        return app()->environment(['local', 'testing']);
    }
}
