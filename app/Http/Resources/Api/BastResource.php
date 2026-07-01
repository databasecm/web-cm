<?php

namespace App\Http\Resources\Api;

use App\Models\Bast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Bast
 */
class BastResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'signed_customer' => $this->signed_customer,
            'signed_company' => $this->signed_company,
            'signed_customer_by' => $this->whenLoaded('customerSigner', fn () => $this->customerSigner?->name),
            'signed_company_by' => $this->whenLoaded('companySigner', fn () => $this->companySigner?->name),
            'signed_at' => $this->signed_at?->toIso8601String(),
            'file' => $this->file,
        ];
    }
}
