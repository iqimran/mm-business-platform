<?php

namespace App\Modules\Restaurant\Http\Requests;

use App\Modules\Branch\Rules\AccessibleBranch;
use App\Modules\Restaurant\Enums\PaymentMethod;
use App\Modules\Restaurant\Models\RestaurantExpense;
use App\Modules\Shared\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRestaurantExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', RestaurantExpense::class);
    }

    protected function prepareForValidation(): void
    {
        foreach (['description', 'reference', 'payment_reference'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field)) ?: null]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'string', new AccessibleBranch, Rule::exists('branches', 'id')->where('is_active', true)],
            'category_id' => ['required', 'string', Rule::exists('restaurant_expense_categories', 'id')->where('is_active', true)],
            'supplier_id' => ['sometimes', 'nullable', 'string', Rule::exists('restaurant_suppliers', 'id')->where('is_active', true)],
            'expense_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:today'],
            'amount' => ['required', new MoneyAmount],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            // Supplier dues: amount paid now (default: full amount). Without a supplier it must be the full amount.
            'paid_amount' => ['sometimes', 'nullable', new MoneyAmount(allowZero: true)],
            'payment_method' => ['sometimes', 'nullable', Rule::enum(PaymentMethod::class)],
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.exists' => 'The selected branch is invalid.',
            'category_id.exists' => 'Select an active expense category.',
            'supplier_id.exists' => 'Select an active supplier.',
            'expense_date.before_or_equal' => 'The expense date cannot be in the future.',
        ];
    }
}
