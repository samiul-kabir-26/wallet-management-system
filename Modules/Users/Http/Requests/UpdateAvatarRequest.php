<?php

namespace Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAvatarRequest extends FormRequest
{
    /**
     * Any signed-in account may change its own avatar.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Raster formats only: SVG can carry script and is served from the app's own origin.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000'],
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
            'avatar.mimes' => 'The photo must be a JPG, PNG or WebP image.',
            'avatar.max' => 'The photo must not be larger than 2 MB.',
            'avatar.dimensions' => 'The photo must be between 64 and 4000 pixels on each side.',
        ];
    }
}
