<?php

namespace Modules\Authentication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Unique;

class RegisterRequest extends FormRequest
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
     * @return array<string, array<int, string|In|Unique>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'phone_number' => [
                'required',
                'string',
                'regex:/^01[3-9]\d{8}$/',
                Rule::unique('users', 'phone_number'),
            ],
            'pin' => ['required', 'string', 'digits_between:5,10', 'confirmed'],
            'role' => ['required', 'string', Rule::in(['USER', 'AGENT'])],
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
            'phone_number.unique' => 'This phone number is already registered.',
            'pin.digits_between' => 'The PIN must be between 5 and 10 digits.',
            'pin.confirmed' => 'The PIN confirmation does not match.',
            'role.in' => 'The selected role is invalid. Allowed roles are USER or AGENT.',
        ];
    }
}
