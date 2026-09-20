<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentInfo extends Model
{
    public function user():BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function approvedBy():BelongsTo {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function suspendedBy():BelongsTo {
        return $this->belongsTo(User::class, 'suspended_by');
    }
}
