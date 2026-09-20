<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRole extends Model
{
    public function user():BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function role():BelongsTo {
        return $this->belongsTo(Role::class);
    }

    public function assignedByUser():BelongsTo {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
