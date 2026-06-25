<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Bidang;
use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, HasRoles, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'bidang',
        'is_protected',
        'created_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // Sensitive 2FA material: kept out of serialization and, because the
        // Auditable trait derives its redaction set from $hidden, out of the
        // audit trail too.
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_protected' => 'boolean',
            'bidang' => Bidang::class,
        ];
    }

    /**
     * Whether this account may access the internal Filament panel (/sistem).
     * Internal staff plus Mitra (4) and Mandor (5) are allowed; Konsumen (6)
     * are denied — they use the separate consumer app. An account without a
     * role is denied. Enforced server-side by Filament's Authenticate
     * middleware (CLAUDE.md §6). The two-factor gate is applied separately.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        $level = $this->level();

        return $level !== null && $level < Role::LEVEL_KONSUMEN;
    }

    /**
     * The account's primary role (source of the hierarchy level).
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * The account that created this account.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Accounts created by this account.
     */
    public function createdUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    /**
     * Hierarchy level of the account (1 = Owner .. 6 = Konsumen), or null
     * when no role is assigned yet.
     */
    public function level(): ?int
    {
        return $this->role?->level;
    }

    /**
     * Whether this account may manage other accounts at all. Owner (1) and
     * Direktur (2) always can; among Management (3) only Manager carries the
     * capability — Finance/HR manage finance/HR data, not accounts. Mitra (4),
     * Mandor (5) and Konsumen (6) never do (CLAUDE.md §6.3).
     */
    public function canManageAccounts(): bool
    {
        return match ($this->level()) {
            Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR => true,
            Role::LEVEL_MANAGEMENT => $this->role?->name === Role::NAME_MANAGER,
            default => false,
        };
    }

    /**
     * Whether this account's reach is scoped to a single business unit. Holds
     * for Management/Manager (3) and Mandor (5) that carry a `bidang`
     * (CLAUDE.md §6.4).
     */
    public function isBidangScoped(): bool
    {
        return in_array($this->level(), [
            Role::LEVEL_MANAGEMENT,
            Role::LEVEL_MANDOR,
        ], true) && $this->bidang !== null;
    }

    /**
     * Whether this account is a Mitra Pembiayaan / Supplier (level 4). Used to
     * apply the read-only, own-project-only scope (CLAUDE.md §6.5).
     */
    public function isBankMitra(): bool
    {
        return $this->level() === Role::LEVEL_MITRA;
    }

    /**
     * Whether two-factor authentication is mandatory for this account. Required
     * for levels 1–3 (Owner, Direktur, Manager, Finance, HR); optional for
     * Mitra (4), Mandor (5) and Konsumen (6).
     */
    public function requiresTwoFactor(): bool
    {
        $level = $this->level();

        return $level !== null && $level <= Role::LEVEL_MANAGEMENT;
    }

    /**
     * Whether this account outranks the given one in the hierarchy (a strictly
     * smaller level number). Equal levels do not outrank each other.
     */
    public function outranks(User $other): bool
    {
        $mine = $this->level();
        $theirs = $other->level();

        return $mine !== null && $theirs !== null && $mine < $theirs;
    }

    /**
     * Roles this account may assign when creating/editing others: every role
     * strictly below its own level. Empty for accounts without management
     * capability. Mirrors the `assign-account` gate so the UI only offers what
     * the policy will accept.
     *
     * @return Collection<int, Role>
     */
    public function assignableRoles(): Collection
    {
        $level = $this->level();

        if (! $this->canManageAccounts() || $level === null) {
            return Role::query()->whereRaw('1 = 0')->get();
        }

        return Role::query()->where('level', '>', $level)->orderBy('level')->get();
    }
}
