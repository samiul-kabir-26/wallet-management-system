<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Values are stored as JSON scalars; SystemSettingService reads them back as floats.
 *
 * @property int $id
 * @property string $key
 * @property float|int|string|bool|null $value
 * @property string|null $description
 * @property int|null $updated_by
 */
#[Fillable([
    'key',
    'value',
    'description',
])]
class SystemSetting extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
