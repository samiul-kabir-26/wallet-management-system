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
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }
}
