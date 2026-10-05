<?php

namespace Modules\Authentication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string|Password>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'otp_code' => ['required', 'string', 'digits:6'],
            'new_password' => [
                'required',
                'string',
                'confirmed',
                Password::defaults(),
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
            'email.required' => 'An email address is required.',
            'email.email' => 'A valid email address is required.',
            'otp_code.required' => 'The OTP code is required.',
            'otp_code.digits' => 'The OTP code must be exactly 6 digits.',
            'new_password.required' => 'The new password is required.',
            'new_password.confirmed' => 'The password confirmation does not match.',
        ];
    }
}
