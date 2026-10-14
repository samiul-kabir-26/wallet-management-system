<?php

namespace Modules\SystemSettings\Http\Requests;

use App\Models\SystemSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSessionTimeoutsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageSessionTimeouts', SystemSetting::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $min = (int) config('auth_settings.tokens.idle_timeout_bounds.min', 1);
        $max = (int) config('auth_settings.tokens.idle_timeout_bounds.max', 120);

        return [
            'admin' => ['sometimes', 'integer', "min:{$min}", "max:{$max}"],
            'agent' => ['sometimes', 'integer', "min:{$min}", "max:{$max}"],
            'user' => ['sometimes', 'integer', "min:{$min}", "max:{$max}"],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->hasAny(['admin', 'agent', 'user'])) {
                $validator->errors()->add('session_timeouts', 'Provide a timeout for at least one panel.');
            }
        });
    }
}
