<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Marketing\LeadService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicFormController extends Controller
{
    private const MESSAGES = [
        'quote' => 'Thank you. Your quotation request has been received and the team will review it.',
        'pos_demo' => 'Thank you. Your demonstration request has been received and the team will be in touch.',
        'contact' => 'Thank you for your message. The team will review it.',
        'callback' => 'Thank you. Your callback request has been received.',
        'partnership' => 'Thank you. Your partnership enquiry has been received.',
    ];

    public function store(string $form, Request $request, LeadService $leads): JsonResponse
    {
        $type = LeadService::FORM_TYPES[$form] ?? null;
        if ($type === null) {
            abort(404);
        }

        $data = $request->validate(LeadService::rules($type));
        $result = $leads->capture($type, $data, $request, $request->header('Idempotency-Key'));

        if ($result['spam']) {
            return ApiResponse::success([
                'reference' => 'RECEIVED',
                'message' => self::MESSAGES[$type],
            ], 201);
        }

        return ApiResponse::success([
            'reference' => $result['lead']?->reference,
            'created' => $result['created'],
            'message' => self::MESSAGES[$type],
        ], 201);
    }
}
