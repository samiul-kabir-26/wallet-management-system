<?php

namespace Modules\Transactions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveWithdrawalRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('externalReference') && ! $this->has('external_reference')) {
            $this->merge(['external_reference' => $this->input('externalReference')]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('reviewWithdrawal', $this->route('transaction')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'external_reference' => ['required', 'string', 'max:255', Rule::unique('transactions', 'external_reference')],
            'note' => ['nullable', 'string', 'max:500'],
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
            'external_reference.required' => 'Enter the bank transfer reference for this payout.',
            'external_reference.unique' => 'This external reference has already been used.',
        ];
    }
}
