<?php

namespace App\Modules\Restaurant\Http\Requests;

use App\Modules\Branch\Rules\AccessibleBranch;
use App\Modules\Restaurant\Enums\PaymentMethod;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Support\SaleFormulas;
use App\Modules\Shared\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * New food sale. Lines carry only menu item + quantity: prices are always taken from the menu.
 */
class StoreFoodSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FoodSale::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('notes'))) {
            $this->merge(['notes' => trim($this->input('notes')) ?: null]);
        }
    }

    public function rules(): array
    {
        $max = SaleFormulas::MAX_QUANTITY;

        return [
            'branch_id' => ['required', 'string', new AccessibleBranch, Rule::exists('branches', 'id')->where('is_active', true)],
            'customer_id' => ['sometimes', 'nullable', 'string', Rule::exists('restaurant_customers', 'id')->where('is_active', true)],
            'sold_at' => ['sometimes', 'nullable', 'date_format:Y-m-d H:i', 'after_or_equal:2000-01-01', 'before_or_equal:now'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array'],
            'items.*.menu_item_id' => ['required', 'string', 'distinct', Rule::exists('restaurant_menu_items', 'id')->where('is_active', true)],
            'items.*.quantity' => ['required', 'integer', "between:1,{$max}"],
            'payment' => ['sometimes', 'nullable', 'array'],
            'payment.amount' => ['required_with:payment', new MoneyAmount],
            'payment.method' => ['required_with:payment', Rule::enum(PaymentMethod::class)],
            'payment.reference' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        $max = SaleFormulas::MAX_QUANTITY;

        return [
            'customer_id.exists' => 'Select an active customer.',
            'branch_id.exists' => 'The selected branch is invalid.',
            'sold_at.before_or_equal' => 'The sale time cannot be in the future.',
            'items.required' => 'Add at least one menu item.',
            'items.min' => 'Add at least one menu item.',
            'items.*.menu_item_id.exists' => 'This menu item is not available.',
            'items.*.menu_item_id.distinct' => 'This menu item is already in the sale; change its quantity instead.',
            'items.*.quantity.between' => "The quantity must be between 1 and {$max}.",
        ];
    }
}
