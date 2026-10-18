<?php

namespace Modules\Transactions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitFloatRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAgent() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The bank reference must not already back a credited transaction; the deposit must be recent so
     * staff can still match it against the bank statement.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $min = config('transactions.limits.float_issue.min', 100.00);
        $max = config('transactions.limits.float_issue.max', 500000.00);

        return [
            'amount' => ['required', 'decimal:0,2', "min:{$min}", "max:{$max}"],
            'bank_reference' => ['required', 'string', 'min:4', 'max:100', 'regex:/^[A-Za-z0-9\-\/_. ]+$/', 'unique:transactions,external_reference'],
            'deposited_at' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.now()->subDays(30)->toDateString()],
            'slip' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
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
            'bank_reference.unique' => 'This bank reference has already been credited.',
            'bank_reference.regex' => 'Use only letters, numbers, spaces and - / _ . in the bank reference.',
            'deposited_at.after_or_equal' => 'Deposits older than 30 days must be handled by customer care.',
            'slip.mimes' => 'Upload the deposit slip as a JPG, PNG, WebP or PDF file.',
            'slip.max' => 'The deposit slip must not be larger than 5 MB.',
        ];
    }
}
