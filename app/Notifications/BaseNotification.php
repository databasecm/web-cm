<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The single notification mechanism (Fase 7).
 *
 * Every business notification extends this. It is queued (ShouldQueue) so a
 * status change in a service never blocks on delivery, and its channel list is
 * read from config — append 'mail'/WhatsApp at go-live with no change to any
 * trigger (A3, like MediaService).
 *
 * The stored payload is a fixed, NEUTRAL shape:
 *   { event, title, body, entity_type, entity_id, action_url }
 * The body is a knock on the door — never a money amount, never a sensitive
 * value. The detail lives behind `action_url`, which still re-checks the
 * owning module's policy when opened. A persisted body is a permanent leak
 * surface (DB now, third-party email/WA later), so it stays neutral by design.
 */
abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Delivery channels, read from config so channels can be added at go-live
     * without touching this class or any trigger.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return config('notifications.channels', ['database']);
    }

    /**
     * The database channel persists exactly the neutral payload below. (Laravel
     * falls back to toArray for the database channel, but we are explicit so a
     * future toMail() can shape mail differently without disturbing storage.)
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    /**
     * The fixed, neutral stored shape. Assembled from the subclass hooks so
     * every notification carries the same keys and nothing more.
     *
     * @return array{event: string, title: string, body: string, entity_type: string|null, entity_id: int|string|null, action_url: string|null}
     */
    final public function payload(): array
    {
        return [
            'event' => $this->event(),
            'title' => $this->title(),
            'body' => $this->body(),
            'entity_type' => $this->entityType(),
            'entity_id' => $this->entityId(),
            'action_url' => $this->actionUrl(),
        ];
    }

    /** Stable machine key for this notification kind (e.g. `payment.paid`). */
    abstract public function event(): string;

    /** Short, neutral heading shown in the bell/inbox. */
    abstract public function title(): string;

    /** Neutral one-line body. MUST NOT contain a money amount or sensitive value. */
    abstract public function body(): string;

    /** The subject model's URL alias/type, or null when there is none. */
    abstract public function entityType(): ?string;

    /** The subject model's id, or null. */
    abstract public function entityId(): int|string|null;

    /**
     * A policy-guarded link to the detail (the "gate"), or null. The link is
     * where amounts/documents are revealed — never the body.
     */
    abstract public function actionUrl(): ?string;
}
