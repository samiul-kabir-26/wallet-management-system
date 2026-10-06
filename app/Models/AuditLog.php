<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'actor_id',
    'action',
    'auditable_type',
    'auditable_id',
    'metadata',
])]
class AuditLog extends Model
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
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The actor (user) who performed the action.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The polymorphic entity targeted by the action.
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
