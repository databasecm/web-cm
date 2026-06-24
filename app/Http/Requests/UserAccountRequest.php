<?php

namespace App\Http\Requests;

use App\Enums\Bidang;
use App\Models\Role;
use App\Policies\UserPolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation + authorization for creating and updating user accounts.
 *
 * Authorization layers the RBAC hard rules (CLAUDE.md §6): subclasses gate the
 * action through the {@see UserPolicy} (capability + hierarchy)
 * and the `assign-account` gate (the actor may only assign a role strictly
 * below itself, and — when bidang-scoped — only within its own bidang). Field
 * shape (bidang required for manager/mandor, forbidden otherwise) is enforced
 * here as validation.
 */
abstract class UserAccountRequest extends FormRequest
{
    /**
     * Role names that must carry a bidang; every other role must not.
     *
     * @var list<string>
     */
    protected const BIDANG_REQUIRED_ROLES = [Role::NAME_MANAGER, Role::NAME_MANDOR];

    private ?Role $resolvedRole = null;

    private bool $roleResolved = false;

    /**
     * Base field rules shared by store and update.
     *
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'bidang' => ['nullable', Rule::enum(Bidang::class)],
        ];
    }

    /**
     * Resolve the role referenced by role_id, or null when absent/invalid.
     */
    protected function resolveRole(): ?Role
    {
        if (! $this->roleResolved) {
            $id = $this->input('role_id');
            $this->resolvedRole = $id ? Role::find($id) : null;
            $this->roleResolved = true;
        }

        return $this->resolvedRole;
    }

    /**
     * Role whose bidang shape should be validated for this request (the new
     * role on create; the new-or-existing role on update).
     */
    abstract protected function effectiveRole(): ?Role;

    /**
     * Enforce the bidang shape rule against the effective role.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $role = $this->effectiveRole();

            if ($role === null) {
                return;
            }

            $requiresBidang = in_array($role->name, self::BIDANG_REQUIRED_ROLES, true);
            $bidang = $this->input('bidang');

            if ($requiresBidang && blank($bidang)) {
                $validator->errors()->add('bidang', 'Bidang wajib diisi untuk peran manager dan mandor.');
            }

            if (! $requiresBidang && filled($bidang)) {
                $validator->errors()->add('bidang', 'Peran ini tidak boleh memiliki bidang.');
            }
        });
    }
}
