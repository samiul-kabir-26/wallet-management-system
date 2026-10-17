<?php

namespace Modules\Authentication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangePinRequest extends FormRequest
{
    /**
     * Route middleware already limits this to agent and customer tokens.
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
            'current_pin' => ['required', 'string', 'digits_between:5,10'],
            'new_pin' => ['required', 'string', 'digits_between:5,10', 'confirmed', 'different:current_pin'],
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
            'current_pin.required' => 'Your current PIN is required.',
            'current_pin.digits_between' => 'Your current PIN must be between 5 and 10 digits.',
            'new_pin.required' => 'A new PIN is required.',
            'new_pin.digits_between' => 'The new PIN must be between 5 and 10 digits.',
            'new_pin.confirmed' => 'The new PIN confirmation does not match.',
            'new_pin.different' => 'The new PIN must be different from your current PIN.',
        ];
    }
}
