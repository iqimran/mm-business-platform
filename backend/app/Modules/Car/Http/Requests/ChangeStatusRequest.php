<?php

namespace App\Modules\Car\Http\Requests;

use App\Modules\Car\Enums\CarStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changeStatus', $this->route('car'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason')) ?: null]);
        }
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(CarStatus::class)],
            'reason' => ['sometimes', 'nullable', 'string', 'min:5', 'max:500'],
        ];
    }
}
