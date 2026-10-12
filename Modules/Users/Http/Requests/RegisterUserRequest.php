<?php

namespace Modules\Users\Http\Requests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Roles\Enums\RolePanel;

class RegisterUserRequest extends FormRequest
{
    /**
     * Only users with users.create permission may register users; see UserPolicy::create().
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $roleName = $this->input('role');
        $role = is_string($roleName) ? Role::where('name', $roleName)->first() : null;
        $isAdminPanel = $role !== null && (($role->panel instanceof RolePanel ? $role->panel->value : $role->panel) === RolePanel::Admin->value);

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'role' => ['required', 'string', Rule::exists('roles', 'name')],
            'email' => [
                Rule::when($isAdminPanel, ['required', 'email', 'max:255', Rule::unique('users', 'email')], ['prohibited']),
            ],
            'password' => [
                Rule::when($isAdminPanel, ['required', 'string', 'min:8'], ['prohibited']),
            ],
            'phone_number' => [
                Rule::when(! $isAdminPanel, ['required', 'string', 'regex:/^01[3-9]\d{8}$/', Rule::unique('users', 'phone_number')], ['prohibited']),
            ],
            'pin' => [
                Rule::when(! $isAdminPanel, ['required', 'string', 'digits_between:5,10', 'confirmed'], ['prohibited']),
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
            'phone_number.required' => 'The phone number is required for USER and AGENT accounts.',
            'phone_number.prohibited' => 'The phone number cannot be provided during initial staff registration.',
            'email.required' => 'The email is required for staff accounts.',
            'email.prohibited' => 'The email cannot be provided during initial USER or AGENT registration.',
            'email.unique' => 'This email is already registered.',
            'password.required' => 'A password is required for staff accounts.',
            'password.prohibited' => 'A password cannot be set for USER or AGENT accounts.',
            'pin.required' => 'A PIN is required for USER and AGENT accounts.',
            'pin.prohibited' => 'A PIN cannot be set for staff accounts.',
            'pin.digits_between' => 'The PIN must be between 5 and 10 digits.',
            'pin.confirmed' => 'The PIN confirmation does not match.',
            'role.exists' => 'The selected role is invalid.',
        ];
    }
}
