<?php

namespace Modules\Authentication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterOtpRequest extends FormRequest
{
    /**
     * Public endpoint: anyone may ask for a signup code (rate limited per IP and per number).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'phone_number' => [
                'required',
                'string',
                'regex:/^01[3-9]\d{8}$/',
                Rule::unique('users', 'phone_number'),
            ],
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
            'phone_number.regex' => 'The phone number must be a valid 11-digit Bangladeshi mobile number.',
            'phone_number.unique' => 'This phone number already has an account. Each number can hold one wallet type, so please sign in or use a different number.',
        ];
    }
}
