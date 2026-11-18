<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
final class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'business_type' => $this->business_type,
            'industry' => $this->industry,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'operating_mode' => $this->operating_mode,
            'members_count' => $this->whenCounted('members'),
            'branches_count' => $this->whenCounted('branches'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
