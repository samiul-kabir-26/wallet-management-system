<?php

namespace Modules\Authentication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResetPinRequest extends FormRequest
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
            'pin.required' => 'The new PIN is required.',
            'pin.digits_between' => 'The PIN must be between 5 and 10 digits.',
            'pin.confirmed' => 'The PIN confirmation does not match.',
        ];
    }
}
