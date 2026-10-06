<?php

namespace Modules\Authentication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetPinRequest extends FormRequest
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
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'otp_code' => ['required', 'digits:6'],
            'pin' => ['required', 'string', 'digits_between:5,10', 'confirmed'],
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
            'otp_code.required' => 'The OTP code is required.',
            'otp_code.digits' => 'The OTP code must be exactly 6 digits.',
            'pin.required' => 'A PIN is required.',
            'pin.digits_between' => 'The PIN must be between 5 and 10 digits.',
            'pin.confirmed' => 'The PIN confirmation does not match.',
        ];
    }
}
