<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Authorization for reading and marking notifications (Fase 7).
 *
 * A notification belongs to exactly one notifiable. Only that owner may see it
 * or mark it read — there is no cross-account visibility, and no role ever
 * reads another account's inbox. Both the Filament bell and the Sanctum API
 * (7-2) gate on this single rule.
 */
class NotificationPolicy
{
    public function view(User $user, DatabaseNotification $notification): bool
    {
        return $this->owns($user, $notification);
    }

    public function update(User $user, DatabaseNotification $notification): bool
    {
        return $this->owns($user, $notification);
    }

    /** True only when the notification is addressed to this exact account. */
    private function owns(User $user, DatabaseNotification $notification): bool
    {
        return $notification->notifiable_type === $user->getMorphClass()
            && (string) $notification->notifiable_id === (string) $user->getKey();
    }
}
