<?php

namespace Modules\Transactions\Http\Requests;

use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;

class TransferRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('recipientId') && ! $this->has('recipient_id')) {
            $this->merge(['recipient_id' => $this->input('recipientId')]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('transfer', Transaction::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'recipient_id' => [
                'required',
                'integer',
                'exists:users,id',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ((int) $value === (int) $this->user()?->id) {
                        $fail('You cannot transfer money to yourself.');
                    }
                },
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }
}
