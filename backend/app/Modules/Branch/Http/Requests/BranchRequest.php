<?php

namespace App\Modules\Branch\Http\Requests;

use App\Modules\Branch\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and update (PUT/PATCH) share rules; updates accept partial payloads.
 */
class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->isMethod('POST')
            ? $this->user()->can('create', Branch::class)
            : $this->user()->can('update', $this->route('branch'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('branches', 'code')->ignore($this->route('branch'))],
            'name' => [$required, 'string', 'max:150', 'regex:/\S/'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
