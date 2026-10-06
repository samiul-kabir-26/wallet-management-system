<?php

namespace Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string'],
            'image' => ['sometimes', 'nullable', 'string'],
            'email' => ['prohibited'],
            'phone_number' => ['prohibited'],
            'password' => ['prohibited'],
            'role' => ['prohibited'],
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
            'email.prohibited' => 'Email cannot be updated through this endpoint.',
            'phone_number.prohibited' => 'Phone number cannot be updated through this endpoint.',
            'password.prohibited' => 'Password cannot be updated through this endpoint.',
            'role.prohibited' => 'Role cannot be updated through this endpoint.',
        ];
    }
}
