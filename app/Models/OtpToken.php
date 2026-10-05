<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'otp_code',
    'purpose',
    'expires_at',
    'used_at',
    'attempt_count',
])]
class OtpToken extends Model
{

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user():BelongsTo {
        return $this->belongsTo(User::class);
    }
}
