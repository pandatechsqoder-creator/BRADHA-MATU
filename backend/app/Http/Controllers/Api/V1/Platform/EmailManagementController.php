<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Mail\AdminEmailTestMail;
use App\Models\EmailDeliveryLog;
use App\Models\EmailProviderSetting;
use App\Services\Email\TransactionalEmailService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmailManagementController extends Controller
{
    public function settings(): JsonResponse
    {
        $settings = EmailProviderSetting::query()->latest()->first();

        if ($settings === null) {
            return ApiResponse::success([
                'provider' => 'smtp',
                'host' => config('mail.mailers.smtp.host'),
                'port' => config('mail.mailers.smtp.port'),
                'encryption' => config('mail.mailers.smtp.scheme'),
                'username' => config('mail.mailers.smtp.username'),
                'password_set' => filled(config('mail.mailers.smtp.password')),
                'from_address' => config('mail.from.address'),
                'from_name' => config('mail.from.name'),
                'reply_to_address' => null,
                'active' => true,
            ]);
        }

        return ApiResponse::success([
            'provider' => $settings->provider,
            'host' => $settings->host,
            'port' => $settings->port,
            'encryption' => $settings->encryption,
            'username' => $settings->username,
            'password_set' => filled($settings->password),
            'from_address' => $settings->from_address,
            'from_name' => $settings->from_name,
            'reply_to_address' => $settings->reply_to_address,
            'active' => $settings->active,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:smtp,log'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'encryption' => ['nullable', 'string', 'in:tls,ssl,smtps'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:2048'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:100'],
            'reply_to_address' => ['nullable', 'email', 'max:255'],
        ]);

        $settings = EmailProviderSetting::query()->latest()->first() ?? new EmailProviderSetting;
        $settings->fill(collect($data)->except(['password'])->all());
        if (array_key_exists('password', $data) && $data['password'] !== '') {
            $settings->password = $data['password'];
        }
        $settings->active = true;
        $settings->save();

        return $this->settings();
    }

    public function test(Request $request, TransactionalEmailService $emails): JsonResponse
    {
        $data = $request->validate(['recipient' => ['required', 'email', 'max:255']]);

        $log = $emails->queue(new AdminEmailTestMail, $data['recipient'], 'admin.test');

        return ApiResponse::success([
            'status' => $log->status,
            'delivery_id' => $log->uuid,
            'delivered' => false,
        ], 202);
    }

    public function logs(Request $request): JsonResponse
    {
        $logs = EmailDeliveryLog::query()
            ->latest()
            ->paginate(min((int) $request->integer('per_page', 30), 100));

        return ApiResponse::success($logs);
    }

    public function retry(Request $request, EmailDeliveryLog $log, TransactionalEmailService $emails): JsonResponse
    {
        $log = $emails->retry($log);

        return ApiResponse::success(['status' => $log->status, 'delivery_id' => $log->uuid], 202);
    }
}
