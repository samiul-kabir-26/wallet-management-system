<?php

namespace Modules\Authentication\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrantAdminAccessRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $this->user()?->can('grantAdminAccess', $target) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $targetUserId = $this->route('user') instanceof User
            ? $this->route('user')->id
            : $this->route('user');

        return [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($targetUserId),
            ],
            'role' => [
                'required',
                'string',
                Rule::exists('roles', 'name')->where('panel', 'admin'),
                Rule::notIn(['SUPER_ADMIN']),
            ],
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
            'email.required' => 'An email address is required.',
            'email.email' => 'A valid email address is required.',
            'email.unique' => 'This email address is already in use.',
            'role.required' => 'A role must be selected.',
            'role.exists' => 'The selected role must exist and belong to the admin panel.',
            'role.not_in' => 'The SUPER_ADMIN role cannot be granted through an admin access invite.',
        ];
    }
}
