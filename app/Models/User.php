<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'email',
    'password',
    'phone_number',
    'pin',
    'image',
    'address',
])]
#[Hidden(['password', 'pin'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function roles():HasMany {
        return $this->hasMany(UserRole::class);
    }

    public function wallet():HasOne {
        return $this->hasOne(Wallet::class);
    }

    public function cap():HasOne {
        return $this->hasOne(Cap::class);
    }

    public function agentInfo():HasOne {
        return $this->hasOne(AgentInfo::class);
    }

    public function transactions():HasMany {
        return $this->hasMany(Transaction::class);
    }

    public function sentTransactions():HasMany {
        return $this->hasMany(Transaction::class, 'sender_id');
    }

    public function receivedTransactions():HasMany {
        return $this->hasMany(Transaction::class, 'recipient_id');
    }

    public function agentTransactions():HasMany {
        return $this->hasMany(Transaction::class, 'agent_id');
    }

    public function initiatedTransactions():HasMany {
        return $this->hasMany(Transaction::class, 'initiated_by');
    }

    public function assignedRoles():HasMany {
        return $this->hasMany(UserRole::class, 'assigned_by');
    }

    public function updatedSettings():HasMany {
        return $this->hasMany(SystemSetting::class, 'updated_by');
    }

    public function createdOtps():HasMany {
        return $this->hasMany(OtpToken::class);
    }

    public function authProviders():HasMany {
        return $this->hasMany(AuthProvider::class);
    }
}

