<?php

namespace App\Http\Resources\Api;

use App\Models\Rab;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rab
 */
class RabResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'total_material' => $this->total_material,
            'total_upah' => $this->total_upah,
            'overhead' => $this->overhead,
            'margin' => $this->margin,
            'ppn' => $this->ppn,
            'grand_total' => $this->grand_total,
        ];
    }
}
