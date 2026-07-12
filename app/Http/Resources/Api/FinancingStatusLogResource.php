<?php

namespace App\Http\Resources\Api;

use App\Models\FinancingStatusLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FinancingStatusLog
 */
class FinancingStatusLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'note' => $this->note,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
