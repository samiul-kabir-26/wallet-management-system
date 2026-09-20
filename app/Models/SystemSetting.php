<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'system_fee_rate',
    'agent_commission_rate',
    // other settings that should be assignable
])]
class SystemSetting extends Model
{
    public function updatedByUser():BelongsTo {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
