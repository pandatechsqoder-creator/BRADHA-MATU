<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

final class PasswordController extends Controller
{
    public function changeInitial(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)->letters()->numbers()->mixedCase()->symbols()],
        ]);

        $user = $request->user();
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => ['Choose a password different from your temporary password.']]);
        }

        $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
        ])->save();

        DB::table('password_change_events')->insert([
            'user_id' => $user->id,
            'reason' => 'initial_password_created',
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return ApiResponse::success(['password_changed' => true]);
    }

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        Password::sendResetLink(['email' => strtolower(trim($data['email']))]);

        return ApiResponse::success(['sent' => true]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)->letters()->numbers()->mixedCase()->symbols()],
        ]);

        $status = Password::reset($data, function ($user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'must_change_password' => false,
            ])->save();
            $user->setRememberToken(null);
            $user->save();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return ApiResponse::success(['password_reset' => true]);
    }
}
