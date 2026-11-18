<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public provider callbacks. A callback is only accepted when its HMAC
 * signature matches and the referenced payment instruction exists.
 */
final class PaymentCallbackController extends Controller
{
    public function mpesa(Request $request, PaymentService $payments): JsonResponse
    {
        return $this->handle('mpesa_sandbox', $request, $payments);
    }

    public function card(Request $request, PaymentService $payments): JsonResponse
    {
        return $this->handle('card_sandbox', $request, $payments);
    }

    private function handle(string $provider, Request $request, PaymentService $payments): JsonResponse
    {
        $raw = $request->getContent();
        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return ApiResponse::success(['status' => 'rejected', 'message' => 'Malformed callback payload.'], 202);
        }

        $header = (string) config('payments.callbacks.signature_header', 'X-Brathamatu-Signature');
        $signature = strtolower((string) $request->header($header));
        $secret = (string) config('payments.callbacks.sandbox_secret', '');
        $valid = $secret !== '' && $signature !== ''
            && hash_equals(hash_hmac('sha256', $raw, $secret), $signature);

        $result = $payments->handleCallback($provider, $payload, $valid, $valid ? '' : 'Invalid or missing signature.');

        return ApiResponse::success($result, 202);
    }
}
