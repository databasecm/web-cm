<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single key-value application setting (ADR-0006). Read through SettingService,
 * which caches the whole set — avoid querying this model directly.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'value',
    ];
}
