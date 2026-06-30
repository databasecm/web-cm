<?php

namespace App\Http\Resources\Api;

use App\Models\Design;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Design
 */
class DesignResource extends JsonResource
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
            'file' => $this->file,
            'notes' => $this->notes,
            'approved_at' => $this->approved_at?->toIso8601String(),
        ];
    }
}
