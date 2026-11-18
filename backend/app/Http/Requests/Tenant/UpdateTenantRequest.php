<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTenantRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'business_type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'max:80'],
            'operating_mode' => ['sometimes', Rule::in(['brathamatu_pos', 'external', 'manual'])],
            'status' => ['sometimes', Rule::in(['pending', 'active', 'suspended'])],
        ];
    }
}
