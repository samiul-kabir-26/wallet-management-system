<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemSetting extends Model
{
    public function updatedByUser():BelongsTo {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
