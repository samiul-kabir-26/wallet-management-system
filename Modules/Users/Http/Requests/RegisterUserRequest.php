<?php

namespace Modules\Users\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Unique;

class RegisterUserRequest extends FormRequest
{
    /**
     * Only ADMIN/SUPER_ADMIN may register users; see UserPolicy::create().
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
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
            'role' => ['required', 'string', Rule::in(['USER', 'AGENT', 'ADMIN', 'MODERATOR'])],
            'email' => [
                'required_if:role,ADMIN,MODERATOR',
                'prohibited_if:role,AGENT,USER',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'password' => [
                'required_if:role,ADMIN,MODERATOR',
                'prohibited_if:role,AGENT,USER',
                'string',
                'min:8',
            ],
            'phone_number' => [
                'required_if:role,AGENT,USER',
                'prohibited_if:role,ADMIN,MODERATOR',
                'string',
                'regex:/^01[3-9]\d{8}$/',
                Rule::unique('users', 'phone_number'),
            ],
            'pin' => [
                'required_if:role,AGENT,USER',
                'prohibited_if:role,ADMIN,MODERATOR',
                'string',
                'digits_between:5,10',
                'confirmed',
            ],
            'address' => ['nullable', 'string'],
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
            'phone_number.required_if' => 'The phone number is required for USER and AGENT accounts.',
            'phone_number.prohibited_if' => 'The phone number cannot be provided during initial ADMIN or MODERATOR registration.',
            'email.required_if' => 'The email is required for ADMIN and MODERATOR accounts.',
            'email.prohibited_if' => 'The email cannot be provided during initial USER or AGENT registration.',
            'email.unique' => 'This email is already registered.',
            'password.required_if' => 'A password is required for ADMIN and MODERATOR accounts.',
            'password.prohibited_if' => 'A password cannot be set for USER or AGENT accounts.',
            'pin.required_if' => 'A PIN is required for USER and AGENT accounts.',
            'pin.prohibited_if' => 'A PIN cannot be set for ADMIN or MODERATOR accounts.',
            'pin.digits_between' => 'The PIN must be between 5 and 10 digits.',
            'pin.confirmed' => 'The PIN confirmation does not match.',
            'role.in' => 'The selected role is invalid. Allowed roles are USER, AGENT, ADMIN, or MODERATOR.',
        ];
    }
}
