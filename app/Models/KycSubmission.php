<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An identity document a customer sent for verification.
 *
 * @property int $id
 * @property int $user_id
 * @property string $document_type
 * @property string $document_number Encrypted at rest.
 * @property string $full_name
 * @property Carbon $date_of_birth
 * @property string $front_path
 * @property string $back_path
 * @property string $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 */
#[Fillable([
    'user_id',
    'document_type',
    'document_number',
    'full_name',
    'date_of_birth',
    'front_path',
    'back_path',
    'status',
    'reviewed_by',
    'reviewed_at',
    'review_note',
])]
#[Hidden(['document_number', 'front_path', 'back_path'])]
class KycSubmission extends Model
{
    public const string PENDING = 'PENDING';

    public const string APPROVED = 'APPROVED';

    public const string REJECTED = 'REJECTED';

    /**
     * Private-disk directory for identity document images. Never served publicly.
     */
    public const string DOCUMENT_DIRECTORY = 'kyc';

    /**
     * @var list<string>
     */
    public const array DOCUMENT_TYPES = ['NID', 'PASSPORT', 'DRIVING_LICENSE'];

    protected function casts(): array
    {
        return [
            'document_number' => 'encrypted',
            'date_of_birth' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The document number with all but the last 4 characters hidden.
     */
    public function maskedDocumentNumber(): string
    {
        $number = (string) $this->document_number;

        return strlen($number) <= 4 ? str_repeat('*', strlen($number)) : str_repeat('*', strlen($number) - 4).substr($number, -4);
    }
}
