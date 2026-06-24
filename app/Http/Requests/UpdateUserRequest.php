<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Validates + authorizes updating an existing user account.
 *
 * The target is taken from the `user` route binding. role_id/bidang are
 * optional on update; when omitted the account's current values are kept and
 * re-validated against the actor's reach.
 */
class UpdateUserRequest extends UserAccountRequest
{
    /**
     * Authorize: the actor must pass the hierarchy gate on the existing target
     * and may only (re)assign a role/bidang at or below its own reach.
     */
    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->targetUser();

        if ($actor === null || $target === null || $actor->cannot('update', $target)) {
            return false;
        }

        // An explicitly supplied but invalid role is deferred to validation.
        if ($this->has('role_id') && $this->resolveRole() === null) {
            return true;
        }

        return $actor->can('assign-account', [$this->effectiveRole(), $this->effectiveBidang()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->targetUser();

        return array_merge($this->baseRules(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'role_id' => ['sometimes', 'integer', Rule::exists('roles', 'id')],
            'email' => [
                'sometimes', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($target?->getKey()),
            ],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
        ]);
    }

    /**
     * New role when supplied, otherwise the target's current role.
     */
    protected function effectiveRole(): ?Role
    {
        return $this->resolveRole() ?? $this->targetUser()?->role;
    }

    /**
     * New bidang when the field is present, otherwise the target's current one.
     */
    protected function effectiveBidang(): ?string
    {
        if ($this->has('bidang')) {
            return $this->input('bidang');
        }

        return $this->targetUser()?->bidang?->value;
    }

    /**
     * The account being updated, from the route binding.
     */
    protected function targetUser(): ?User
    {
        $route = $this->route('user');

        return $route instanceof User ? $route : User::find($route);
    }
}
