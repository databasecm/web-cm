<?php

namespace App\Http\Resources\Api;

use App\Models\Installment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Installment
 */
class InstallmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'term_no' => $this->term_no,
            'label' => $this->label,
            'percentage' => $this->percentage,
            'amount' => $this->amount,
            'due_condition' => $this->due_condition->value,
            'due_condition_label' => $this->due_condition->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
        ];
    }
}
