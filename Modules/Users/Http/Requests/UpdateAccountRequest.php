<?php

namespace Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountRequest extends FormRequest
{
    /**
     * Any signed-in account may edit its own profile.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Email and phone number identify the account and its credentials, so they cannot be self-edited.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'receipt_emails_enabled' => ['sometimes', 'boolean'],
            'email' => ['prohibited'],
            'phone_number' => ['prohibited'],
            'image' => ['prohibited'],
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
            'email.prohibited' => 'Your email address cannot be changed.',
            'phone_number.prohibited' => 'Your phone number cannot be changed.',
            'image.prohibited' => 'Upload a new photo through the avatar endpoint.',
        ];
    }
}
