<?php

namespace Modules\Users\Http\Requests;

use App\Models\KycSubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitKycRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Limits apply to customer wallets, so only customers send identity documents.
     */
    public function authorize(): bool
    {
        return $this->user()?->isUser() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', Rule::in(KycSubmission::DOCUMENT_TYPES)],
            'document_number' => ['required', 'string', 'min:5', 'max:30', 'regex:/^[A-Za-z0-9 ]+$/'],
            'full_name' => ['required', 'string', 'min:2', 'max:255'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString(), 'after:1900-01-01'],
            'front' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'back' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
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
            'document_number.regex' => 'Use only letters, numbers and spaces in the document number.',
            'date_of_birth.before_or_equal' => 'You must be at least 18 years old.',
            'front.mimes' => 'Upload a JPG, PNG or WebP photo of the front of the document.',
            'back.mimes' => 'Upload a JPG, PNG or WebP photo of the back of the document.',
            'front.max' => 'Each photo must not be larger than 5 MB.',
            'back.max' => 'Each photo must not be larger than 5 MB.',
        ];
    }
}
