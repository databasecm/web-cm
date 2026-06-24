<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Bidang;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

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
     * Whether this account outranks the given one in the hierarchy (a strictly
     * smaller level number). Equal levels do not outrank each other.
     */
    public function outranks(User $other): bool
    {
        $mine = $this->level();
        $theirs = $other->level();

        return $mine !== null && $theirs !== null && $mine < $theirs;
    }
}
