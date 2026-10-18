<?php

namespace Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCommissionRateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('updateCommission', $this->route('user')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Same bounds as agent approval; the rate is stored to 4 decimal places (0.0125 = 1.25%).
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'commission_rate' => ['required', 'numeric', 'decimal:0,4', 'between:0,1'],
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
            'commission_rate.required' => 'A commission rate is required.',
            'commission_rate.between' => 'The commission rate must be a fraction between 0 and 1 (e.g. 0.01 for 1%).',
            'commission_rate.decimal' => 'The commission rate can have at most 4 decimal places.',
        ];
    }
}
