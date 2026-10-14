<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'panel',
    'identifier',
    'status',
    'failure_reason',
    'ip_address',
    'user_agent',
])]
class LoginActivity extends Model
{
    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    public const ?string UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * The account the attempt was made against, when it could be resolved.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
