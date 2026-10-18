<?php

namespace Modules\Transactions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('reverse', $this->route('transaction')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The reason is shown to both parties' auditors and stored on the reversal row.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
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
            'reason.required' => 'Give a reason for the reversal.',
            'reason.min' => 'The reason must be at least 10 characters so auditors can understand it.',
        ];
    }
}
