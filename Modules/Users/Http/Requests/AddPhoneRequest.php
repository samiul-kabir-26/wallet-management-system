<?php

namespace Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddPhoneRequest extends FormRequest
{
    /**
     * Any signed-in account without a phone number may add one.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
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
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_number.regex' => 'The phone number must be a valid 11-digit Bangladeshi mobile number.',
            'phone_number.unique' => 'This phone number already belongs to another account.',
        ];
    }
}
