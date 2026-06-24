<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Validates + authorizes creating a new user account.
 */
class StoreUserRequest extends UserAccountRequest
{
    /**
     * Authorize: the actor must be able to create accounts and may only assign
     * a role/bidang at or below its own reach. A missing/invalid role is left
     * for validation (422) rather than masked as a forbidden action (403).
     */
    public function authorize(): bool
    {
        $actor = $this->user();

        if ($actor === null || $actor->cannot('create', User::class)) {
            return false;
        }

        $role = $this->resolveRole();

        if ($role === null) {
            return true; // defer to the exists rule (422)
        }

        return $actor->can('assign-account', [$role, $this->input('bidang')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->baseRules(), [
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
        ]);
    }

    protected function effectiveRole(): ?Role
    {
        return $this->resolveRole();
    }
}
