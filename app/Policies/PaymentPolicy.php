<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Who may record a consumer payment into the cash book (Fase 3-4).
 *
 * Segregation of duties: the party that bills a project (a Manager) is NOT the
 * party that records the cash. Recording is Finance's job, with Owner/Direktur
 * able to act above them. Exposed through the `recordPayment` gate.
 */
class PaymentPolicy
{
    public function record(User $actor): bool
    {
        return in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true)
            || $actor->isFinance();
    }
}
