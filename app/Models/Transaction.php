<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'sender_id',
    'recipient_id',
    'agent_id',
    'initiated_by',
    // other fields appropriate for creation
])]
class Transaction extends Model
{
    public function user():BelongsTo {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sender():BelongsTo {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient():BelongsTo {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function agent():BelongsTo {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function initiatedBy():BelongsTo {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
