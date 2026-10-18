<?php

namespace Modules\Transactions\Http\Requests;

use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Asking for money is a customer feature, like sending it.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('transfer', Transaction::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The amount uses the transfer limits, because paying the request is a transfer.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $min = config('transactions.limits.transfer.min', 10.00);
        $max = config('transactions.limits.transfer.max', 25000.00);

        return [
            'payer_phone' => ['required', 'string', 'regex:/^01[3-9]\d{8}$/'],
            'amount' => ['required', 'decimal:0,2', "min:{$min}", "max:{$max}"],
            'note' => ['nullable', 'string', 'max:255'],
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
            'payer_phone.regex' => 'Enter an 11-digit Bangladeshi mobile number (01XXXXXXXXX).',
        ];
    }
}
