<?php

namespace App\Modules\Car\Http\Requests;

use App\Modules\Car\Enums\CarDocumentType;
use App\Modules\Car\Models\CarDocument;
use App\Modules\Car\Support\Normalize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and update (PUT/PATCH). On update, missing fields keep their current values
 * so cross-field rules (custom name, date order) are always checked against the final state.
 */
class CarDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->isMethod('POST') ? 'addDocument' : 'updateDocument', $this->route('car'));
    }

    protected function prepareForValidation(): void
    {
        /** @var CarDocument|null $document */
        $document = $this->route('document');
        if ($document) {
            $this->merge(array_filter([
                'type' => $this->has('type') ? null : $document->type->value,
                'custom_name' => $this->has('custom_name') ? null : $document->custom_name,
                'issue_date' => $this->has('issue_date') ? null : $document->issue_date?->toDateString(),
                'expiry_date' => $this->has('expiry_date') ? null : $document->expiry_date->toDateString(),
            ], fn ($v) => $v !== null));
        }

        $normalized = [];
        foreach (['custom_name', 'document_number', 'notes'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = Normalize::text($this->input($field));
            }
        }
        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CarDocumentType::class)],
            'custom_name' => ['nullable', 'string', 'max:100', 'required_if:type,other', 'prohibited_unless:type,other'],
            'document_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'issue_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1950-01-01', 'before_or_equal:today'],
            'expiry_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date', 'after_or_equal:1950-01-01', 'before:2100-01-01'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'custom_name.required_if' => 'Enter a document name for type "Other".',
            'custom_name.prohibited_unless' => 'A custom name is only used for type "Other".',
            'expiry_date.after_or_equal' => 'The expiry date must be on or after the issue date.',
        ];
    }
}
