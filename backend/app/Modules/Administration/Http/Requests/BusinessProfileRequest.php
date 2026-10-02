<?php

namespace App\Modules\Administration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BusinessProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('setting.update');
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'address', 'phone', 'email'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim(preg_replace('/[ \t]+/', ' ', $this->input($field))) ?: null]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[0-9+()\-\s,\/]+$/'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'Use digits, spaces and + ( ) - , / only (several numbers may be separated by commas).'];
    }
}
