<?php

namespace App\Services\Payments\Providers;

use App\Models\Payment;
use App\Services\Payments\Contracts\PaymentProvider;
use App\Services\Payments\Contracts\ProviderCallback;
use App\Services\Payments\Contracts\ProviderInitiation;
use Illuminate\Support\Str;

/**
 * Sandbox STK Push. It simulates the Daraja request/response contract but no
 * real money moves and the payment stays "processing" until a signed provider
 * callback is received.
 */
final class MpesaSandboxProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'mpesa_sandbox';
    }

    public function supports(string $method): bool
    {
        return $method === 'mpesa';
    }

    public function initiate(Payment $payment, array $context = []): ProviderInitiation
    {
        $checkoutRequestId = 'ws_CO_'.now()->format('YmdHis').Str::upper(Str::random(10));
        $merchantRequestId = (string) Str::uuid();
        $phone = $this->normalisePhone((string) ($context['phone'] ?? ''));

        return new ProviderInitiation(
            status: 'processing',
            providerReference: $checkoutRequestId,
            instructions: [
                'mode' => 'sandbox',
                'label' => 'M-Pesa STK Push (sandbox)',
                'message' => 'A sandbox STK push was sent to '.$this->maskPhone($phone).'. Confirm it in the sandbox simulator; BRATHAMATU only marks this payment paid after the signed provider callback.',
                'checkout_request_id' => $checkoutRequestId,
                'phone' => $this->maskPhone($phone),
                'requires_provider_callback' => true,
            ],
            request: [
                'BusinessShortCode' => (string) config('payments.mpesa.shortcode'),
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => (int) round($payment->amount),
                'PartyA' => $phone,
                'PartyB' => (string) config('payments.mpesa.paybill'),
                'PhoneNumber' => $phone,
                'CallBackURL' => config('payments.mpesa.callback_url'),
                'AccountReference' => $payment->internal_reference,
                'TransactionDesc' => 'BRATHAMATU stock take '.$payment->purpose,
            ],
            response: [
                'MerchantRequestID' => $merchantRequestId,
                'CheckoutRequestID' => $checkoutRequestId,
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing',
                'CustomerMessage' => 'Success. Request accepted for processing',
            ],
        );
    }

    public function parseCallback(array $payload): ProviderCallback
    {
        $checkoutRequestId = (string) ($payload['CheckoutRequestID'] ?? $payload['checkout_request_id'] ?? '');
        $receipt = (string) ($payload['MpesaReceiptNumber'] ?? $payload['mpesa_receipt_number'] ?? '');
        $resultCode = $payload['ResultCode'] ?? $payload['result_code'] ?? null;
        $successful = (string) $resultCode === '0';
        $amount = (float) ($payload['Amount'] ?? $payload['amount'] ?? 0);
        $currency = (string) ($payload['Currency'] ?? 'KES');

        return new ProviderCallback(
            callbackId: $receipt !== '' ? $receipt : ($checkoutRequestId !== '' ? $checkoutRequestId : (string) Str::uuid()),
            providerReference: $checkoutRequestId !== '' ? $checkoutRequestId : null,
            transactionReference: $receipt !== '' ? $receipt : null,
            amount: round($amount, 2),
            currency: strtoupper($currency),
            successful: $successful,
            message: (string) ($payload['ResultDesc'] ?? $payload['result_desc'] ?? ''),
            payload: [
                'CheckoutRequestID' => $checkoutRequestId,
                'MpesaReceiptNumber' => $receipt,
                'ResultCode' => $resultCode,
                'ResultDesc' => $payload['ResultDesc'] ?? null,
                'Amount' => $amount,
                'Currency' => $currency,
                'PhoneNumber' => $this->maskPhone((string) ($payload['PhoneNumber'] ?? '')),
            ],
        );
    }

    private function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            return '254'.substr($digits, 1);
        }

        if (str_starts_with($digits, '7') || str_starts_with($digits, '1')) {
            return '254'.$digits;
        }

        return $digits;
    }

    private function maskPhone(string $phone): string
    {
        return strlen($phone) >= 8 ? substr($phone, 0, 4).'****'.substr($phone, -3) : 'phone';
    }
}
