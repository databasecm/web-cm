<?php

namespace App\Notifications;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place that answers "who is told about this event?" (Fase 7).
 *
 * Triggers never build their own recipient lists — they ask here — so the
 * event→recipient map can never drift out of sync with §6. Two hard rules:
 *
 *   1. FAIL CLOSED. An event with no registered rule notifies no one. Silence
 *      never leaks across a boundary; over-notifying can. When a new event
 *      needs a recipient rule that no existing policy expresses, that rule is
 *      raised to the owner — never invented here.
 *   2. Recipients are always derived from EXISTING relations/policies. Prefer
 *      running candidates through the very policy ability that guards the
 *      detail page (`authorizedTo`), so who gets the knock can never out-run
 *      who may open the door.
 *
 * This is the 7-1 skeleton: the per-event rules land with their triggers
 * (7-3..7-6). The reusable primitives below are already policy/relation-backed.
 */
class RecipientResolver
{
    /**
     * Recipients for a business event about `$entity`. Fail-closed: unknown
     * events resolve to nobody until an explicit, policy-backed rule is added.
     *
     * @return Collection<int, User>
     */
    public function recipientsFor(string $event, Model $entity): Collection
    {
        return User::query()->whereRaw('1 = 0')->get();
    }

    /**
     * Owner + Direktur — the always-authorized overseers (§6.3). Reused by many
     * events; kept here so the "who oversees everything" set is defined once.
     *
     * @return Collection<int, User>
     */
    public function overseers(): Collection
    {
        return User::query()
            ->whereHas('role', fn ($query) => $query->whereIn('level', [
                Role::LEVEL_OWNER,
                Role::LEVEL_DIREKTUR,
            ]))
            ->get();
    }

    /**
     * Keep only candidates a policy actually authorizes for `$ability` on
     * `$entity`. Recipient selection reuses the detail page's gate, so it can
     * never widen access beyond §6 — the core Fase 7 privacy guarantee.
     *
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User>
     */
    public function authorizedTo(string $ability, Collection $candidates, Model $entity): Collection
    {
        return $candidates
            ->filter(fn (User $user) => $user->can($ability, $entity))
            ->values();
    }
}
