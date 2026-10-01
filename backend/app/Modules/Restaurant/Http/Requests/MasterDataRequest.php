<?php

namespace App\Modules\Restaurant\Http\Requests;

use App\Modules\Restaurant\Support\Normalize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared behaviour for restaurant master-data requests:
 * permission check by prefix and input normalization.
 */
abstract class MasterDataRequest extends FormRequest
{
    /** Permission prefix, e.g. "restaurant.customer". */
    abstract protected function permissionPrefix(): string;

    /** Route parameter name of the record being updated. */
    abstract protected function routeKey(): string;

    public function authorize(): bool
    {
        return $this->user()->can($this->permissionPrefix().($this->isMethod('POST') ? '.create' : '.update'));
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['name', 'contact_person', 'address', 'notes', 'description'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = Normalize::text($this->input($field));
            }
        }
        // Keep un-normalizable input as-is so the format rule rejects it instead of silently dropping it.
        if (is_string($this->input('phone')) && preg_match('/\d/', $this->input('phone'))) {
            $normalized['phone'] = Normalize::phone($this->input('phone'));
        }

        $this->merge($normalized);
    }

    protected function required(): string
    {
        return $this->isMethod('POST') ? 'required' : 'sometimes';
    }

    protected function ignoreId(): ?string
    {
        return $this->route($this->routeKey())?->getKey();
    }

    /**
     * @return array<int, mixed>
     */
    protected function phoneRules(string $table): array
    {
        return ['sometimes', 'nullable', 'string', 'regex:/^\+?\d{6,20}$/', Rule::unique($table, 'phone')->ignore($this->ignoreId())];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number.',
            'phone.unique' => 'This phone number is already registered.',
        ];
    }
}
