<?php

namespace App\Http\Resources\Api;

use App\Models\Financing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Financing
 */
class FinancingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project_title' => $this->whenLoaded('project', fn () => $this->project?->title),
            'bank' => $this->whenLoaded('bankMitra', fn () => $this->bankMitra?->name),
            'amount' => $this->amount,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_final' => $this->status->isFinal(),
            'status_logs' => FinancingStatusLogResource::collection($this->whenLoaded('statusLogs')),
        ];
    }
}
