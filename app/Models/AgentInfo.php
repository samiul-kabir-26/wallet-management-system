<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'approved_by',
    'suspended_by',
])]
class AgentInfo extends Model
{

    protected $table = 'agent_info';


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
