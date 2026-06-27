<?php

namespace App\Models;

use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Supplier profile (ERD §A.3). May later be tied to a Mitra Supplier (L4)
 * account; the supplier self-service portal arrives in a later phase.
 */
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_name',
        'phone',
        'address',
    ];

    /**
     * The optional login account (Mitra Supplier, L4) behind this supplier.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Materials supplied.
     */
    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }
}
