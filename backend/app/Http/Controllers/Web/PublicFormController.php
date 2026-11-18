<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Marketing\LeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PublicFormController extends Controller
{
    public function store(string $form, Request $request, LeadService $leads): RedirectResponse
    {
        $type = LeadService::FORM_TYPES[$form] ?? null;
        if ($type === null) {
            abort(404);
        }

        try {
            $data = $request->validate(LeadService::rules($type));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput($request->except(['form_token', 'website']));
        }

        $result = $leads->capture($type, $data, $request, $request->header('Idempotency-Key'));

        if ($result['spam'] || $result['lead'] === null) {
            return back()->with('lead_success', 'Thank you. Your message has been received.');
        }

        return back()->with('lead_success', 'Thank you. Your reference is '.$result['lead']->reference.'. The team will review your request.');
    }
}
