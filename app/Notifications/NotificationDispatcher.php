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
 * Idempotency: an event is identified by (event, subject id). A recipient who
 * already holds a notification for that pair is skipped, so a queue retry or a
 * double call yields exactly one notification per recipient. Distinct subjects
 * (e.g. two different installments) are distinct events and each notifies.
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
                if ($this->alreadyNotified($recipient, $event, $subject)) {
                    continue;
                }

                $recipient->notify($make($recipient));
            }
        } catch (Throwable $e) {
            // The business action already succeeded — a notification failure
            // must never surface to the caller. Record it and move on.
            report($e);
        }
    }

    /**
     * Whether this recipient already has the notification for (event, subject).
     * The subject's key is what the notification stores as entity_id, so the two
     * always align — no extra bookkeeping column is needed.
     */
    private function alreadyNotified(User $recipient, string $event, Model $subject): bool
    {
        return $recipient->notifications()
            ->where('data->event', $event)
            ->where('data->entity_id', $subject->getKey())
            ->exists();
    }
}
