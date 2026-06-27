<?php

namespace App\Models;

use Database\Factories\MaterialPriceHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One price point in a material's history (ERD §A.3). Append-only.
 */
class MaterialPriceHistory extends Model
{
    /** @use HasFactory<MaterialPriceHistoryFactory> */
    use HasFactory;

    protected $table = 'material_price_history';

    protected $fillable = [
        'material_id',
        'price',
        'changed_by',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
