<?php

namespace Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmPhoneVerificationRequest extends FormRequest
{
    /**
     * Any signed-in account may verify its own phone number.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'otp_code' => ['required', 'digits:6'],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'otp_code.required' => 'Enter the code we sent to your phone.',
            'otp_code.digits' => 'The code must be exactly 6 digits.',
        ];
    }
}
