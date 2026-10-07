<?php

namespace Modules\Transactions\Http\Requests;

use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;

class CashOutRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('agentId') && ! $this->has('agent_id')) {
            $this->merge(['agent_id' => $this->input('agentId')]);
        }
    }

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
        return [
            'agent_id' => [
                'required',
                'integer',
                'exists:users,id',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ((int) $value === (int) $this->user()?->id) {
                        $fail('The agent must be a different user.');
                    }
                },
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }
}
