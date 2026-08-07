<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @mixin DatabaseNotification
 *
 * The read shape for a notification (Fase 7-2). It exposes ONLY the neutral
 * stored payload plus read/created timestamps — never `notifiable_type`,
 * `notifiable_id`, or the notification class in `type`. The body is already a
 * knock (no money amount, no sensitive value, §7-1); the detail lives behind
 * the policy-guarded `action_url`.
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];

        return [
            'id' => $this->id,
            'event' => $data['event'] ?? null,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => $data['entity_id'] ?? null,
            'action_url' => $data['action_url'] ?? null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
