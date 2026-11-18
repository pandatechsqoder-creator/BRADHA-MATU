<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'business_type' => ['nullable', 'string', 'max:80'],
            'industry' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:80'],
            'operating_mode' => ['nullable', Rule::in(['brathamatu_pos', 'external', 'manual'])],
        ];
    }
}
