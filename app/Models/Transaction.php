<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
