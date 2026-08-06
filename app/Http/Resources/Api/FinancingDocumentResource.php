<?php

namespace App\Http\Resources\Api;

use App\Models\FinancingDocument;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FinancingDocument
 *
 * The raw `file` object key NEVER leaves the server (ADR-0015): it is model-hidden
 * (redacted in audit / generic serialization) and is not exposed here either.
 * Instead we hand back a short-lived SIGNED `media_url` that still re-checks the
 * FinancingDocument view policy when fetched — the sensitive KTP/payslip can only
 * be reached through that gate, never a guessable key.
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
            'media_url' => $this->file !== null ? app(MediaService::class)->temporaryUrl($this->resource) : null,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'note' => $this->note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
