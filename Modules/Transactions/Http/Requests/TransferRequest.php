<?php

namespace Modules\Transactions\Http\Requests;

use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('recipientPhone') && ! $this->has('recipient_phone')) {
            $this->merge(['recipient_phone' => $this->input('recipientPhone')]);
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
            'recipient_phone' => [
                'required',
                'string',
                'regex:/^01[3-9]\d{8}$/',
                Rule::exists('users', 'phone_number')->where('is_deleted', false),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'pin' => ['required', 'string', 'digits_between:5,10'],
            'description' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipient_phone.regex' => 'The phone number must be a valid 11-digit Bangladeshi mobile number.',
            'recipient_phone.exists' => 'Recipient user not found.',
            'pin.required' => 'The PIN is required.',
            'pin.digits_between' => 'The PIN must be between 5 and 10 digits.',
        ];
    }
}
