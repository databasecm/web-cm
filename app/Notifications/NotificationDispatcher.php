<?php

namespace App\Notifications;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Sends a business notification to its resolved recipients — once each, and
 * never at the expense of the business flow (Fase 7-3).
 *
 * Services call this AFTER their money/state transaction has committed, so:
 *   - a notification is never part of a money transaction, and
 *   - a delivery problem can never roll back or fail the business action —
 *     any error here is reported and swallowed.
 *
 * Idempotency: an event is identified by its stored payload — (event, entity_id,
 * body). A recipient who already holds that exact notification is skipped, so a
 * queue retry or a double call yields one notification per recipient. Distinct
 * subjects (two installments) differ by entity_id; repeatable subjects (a
 * project whose progress moves 30% → 50%) differ by body, so each real step
 * still notifies while a true retry does not.
 */
class NotificationDispatcher
{
    public function __construct(private RecipientResolver $resolver) {}

    /**
     * @param  Closure(User): BaseNotification  $make  builds a fresh notification per recipient
     */
    public function dispatch(string $event, Model $subject, Closure $make): void
    {
        try {
            foreach ($this->resolver->recipientsFor($event, $subject) as $recipient) {
                $notification = $make($recipient);

                if ($this->alreadyNotified($recipient, $notification->payload())) {
                    continue;
                }

                $recipient->notify($notification);
            }
        } catch (Throwable $e) {
            // The business action already succeeded — a notification failure
            // must never surface to the caller. Record it and move on.
            report($e);
        }
    }

    /**
     * Whether this recipient already holds this exact notification, keyed on the
     * stored payload (event, entity_id, body) — no extra bookkeeping column is
     * needed since all three live in the neutral payload.
     *
     * @param  array<string, mixed>  $payload
     */
    private function alreadyNotified(User $recipient, array $payload): bool
    {
        return $recipient->notifications()
            ->where('data->event', $payload['event'])
            ->where('data->entity_id', $payload['entity_id'])
            ->where('data->body', $payload['body'])
            ->exists();
    }
}
