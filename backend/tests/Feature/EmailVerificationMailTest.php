<?php

namespace Tests\Feature;

use App\Mail\VerificationCodeMail;
use App\Models\User;
use App\Models\UserVerification;
use App\Services\Identity\EmailVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailVerificationMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_verification_code_is_emailed_and_only_stored_hashed(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production']);
        Mail::fake();
        $user = User::factory()->create();

        $code = app(EmailVerification::class)->issue($user);

        Mail::assertQueued(VerificationCodeMail::class, fn (VerificationCodeMail $mail) => $mail->code === $code
            && $mail->hasTo($user->email));
        $verification = UserVerification::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('pending', $verification->status);
        $this->assertNotSame($code, $verification->reference);
    }

    public function test_testing_environment_keeps_returning_the_code_without_sending_email(): void
    {
        config(['app.env' => 'testing']);
        Mail::fake();
        $user = User::factory()->create();

        $code = app(EmailVerification::class)->issue($user);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        Mail::assertNothingSent();
    }
}
