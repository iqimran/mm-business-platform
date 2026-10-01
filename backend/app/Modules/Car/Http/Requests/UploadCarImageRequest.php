<?php

namespace App\Modules\Car\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadCarImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('car'));
    }

    public function rules(): array
    {
        return [
            // "image" checks the real content type (SVG excluded); max is in kilobytes.
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=8000,max_height=8000'],
        ];
    }
}
