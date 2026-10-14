<?php

namespace Modules\Transactions\Http\Requests;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueFloatRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('agentPhone') && ! $this->has('agent_phone')) {
            $this->merge(['agent_phone' => $this->input('agentPhone')]);
        }
        if ($this->has('agentId') && ! $this->has('agent_id')) {
            $this->merge(['agent_id' => $this->input('agentId')]);
        }
        if ($this->has('externalReference') && ! $this->has('external_reference')) {
            $this->merge(['external_reference' => $this->input('externalReference')]);
        }
        if ($this->has('idempotencyKey') && ! $this->has('idempotency_key')) {
            $this->merge(['idempotency_key' => $this->input('idempotencyKey')]);
        }

        if (! $this->filled('agent_id') && $this->filled('agent_phone')) {
            $agentId = User::where('phone_number', $this->input('agent_phone'))
                ->where('is_deleted', false)
                ->value('id');
            if ($agentId) {
                $this->merge(['agent_id' => $agentId]);
            }
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('issueFloat', Transaction::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $min = config('transactions.limits.float_issue.min', 100.00);
        $max = config('transactions.limits.float_issue.max', 500000.00);

        $existingTransactionId = null;
        if ($this->user() && $this->filled('idempotency_key')) {
            $existingTransactionId = Transaction::where('initiated_by', $this->user()->id)
                ->where('idempotency_key', $this->input('idempotency_key'))
                ->value('id');
        }

        $uniqueReferenceRule = Rule::unique('transactions', 'external_reference');
        if ($existingTransactionId !== null) {
            $uniqueReferenceRule->ignore($existingTransactionId);
        }

        return [
            'agent_id' => [
                'required_without:agent_phone',
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('is_deleted', false),
            ],
            'agent_phone' => [
                'required_without:agent_id',
                'nullable',
                'string',
                'regex:/^01[3-9]\d{8}$/',
                Rule::exists('users', 'phone_number')->where('is_deleted', false),
            ],
            'amount' => ['required', 'decimal:0,2', "min:{$min}", "max:{$max}"],
            'external_reference' => ['required', 'string', 'max:255', $uniqueReferenceRule],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
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
            'agent_phone.regex' => 'The agent phone number must be a valid 11-digit Bangladeshi mobile number.',
            'agent_phone.exists' => 'Agent user not found.',
            'agent_id.exists' => 'Agent user not found.',
            'external_reference.required' => 'The external reference (bank deposit slip or reference) is required.',
            'external_reference.unique' => 'This external reference has already been used.',
        ];
    }
}
