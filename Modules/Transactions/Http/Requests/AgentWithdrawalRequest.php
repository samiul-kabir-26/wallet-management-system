<?php

namespace Modules\Transactions\Http\Requests;

use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;

class AgentWithdrawalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('agentWithdrawal', Transaction::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $min = config('transactions.limits.agent_withdrawal.min', 10.00);
        $max = config('transactions.limits.agent_withdrawal.max', 50000.00);

        return [
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
            'pin.required' => 'The PIN is required.',
            'pin.digits_between' => 'The PIN must be between 5 and 10 digits.',
        ];
    }
}
