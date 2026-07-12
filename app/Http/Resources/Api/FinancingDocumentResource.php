<?php

namespace App\Http\Resources\Api;

use App\Models\FinancingDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FinancingDocument
 *
 * The `file` pointer is model-hidden (redacted in audit / generic serialization);
 * it is exposed here deliberately because the endpoint already authorized the
 * owning consumer to see their own document.
 */
class FinancingDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'file' => $this->file,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'note' => $this->note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
