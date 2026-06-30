<?php

namespace App\Http\Resources\Api;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'bidang' => $this->bidang?->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'contract_value' => $this->contract_value,
            'progress_percent' => $this->progress_percent,
            'payment_scheme' => $this->payment_scheme?->value,
        ];
    }
}
