<?php

namespace Modules\Transactions\Http\Requests;

use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;

class CashOutRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('cashOut', Transaction::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $min = config('transactions.limits.cash_out.min', 10.00);
        $max = config('transactions.limits.cash_out.max', 25000.00);

        return [
            'agent_phone' => [
                'required',
                'string',
                'regex:/^01[3-9]\d{8}$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ((string) $value === (string) $this->user()?->phone_number) {
                        $fail('The agent must be a different user.');
                    }
                },
            ],
            'amount' => ['required', 'decimal:0,2', "min:{$min}", "max:{$max}"],
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
            'agent_phone.regex' => 'The phone number must be a valid 11-digit Bangladeshi mobile number.',
            'pin.required' => 'The PIN is required.',
            'pin.digits_between' => 'The PIN must be between 5 and 10 digits.',
        ];
    }
}
