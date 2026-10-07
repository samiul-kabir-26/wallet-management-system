<?php

namespace Modules\SystemSettings\Http\Requests;

use App\Models\SystemSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSystemSettingsRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('transactionFee') && ! $this->has('system_fee_rate')) {
            $this->merge(['system_fee_rate' => $this->input('transactionFee')]);
        }

        if ($this->has('agentCommission') && ! $this->has('agent_commission_rate')) {
            $this->merge(['agent_commission_rate' => $this->input('agentCommission')]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', SystemSetting::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'system_fee_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'agent_commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('system_fee_rate') && ! $this->has('agent_commission_rate')) {
                $validator->errors()->add('settings', 'At least one system setting must be provided.');
            }
        });
    }
}
