<?php

namespace App\Modules\Restaurant\Http\Requests\Concerns;

use App\Modules\Restaurant\Support\FoodPackageFormulas;
use App\Modules\Shared\Rules\MoneyAmount;
use Illuminate\Validation\Rule;

/**
 * Hall charge and optional event food package of a booking request.
 * Totals are never accepted from the client; the server calculates them.
 */
trait ValidatesFoodPackage
{
    /** "agreed_amount" is accepted as the former name of the hall charge. */
    protected function normalizeBookingAmounts(): void
    {
        if (! $this->has('hall_charge') && $this->has('agreed_amount')) {
            $this->merge(['hall_charge' => $this->input('agreed_amount')]);
        }
        $package = $this->input('food_package');
        if (is_array($package)) {
            foreach (['name', 'notes'] as $field) {
                if (is_string($package[$field] ?? null)) {
                    $package[$field] = trim($package[$field]) ?: null;
                }
            }
            $this->merge(['food_package' => $package]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function foodPackageRules(): array
    {
        $max = FoodPackageFormulas::MAX_GUESTS;

        return [
            'food_package' => ['sometimes', 'nullable', 'array', 'required_array_keys:name,guest_count,price_per_head,event_menu_item_ids'],
            'food_package.name' => ['required_with:food_package', 'string', 'max:150'],
            'food_package.guest_count' => ['required_with:food_package', 'integer', "between:1,{$max}"],
            'food_package.price_per_head' => ['required_with:food_package', new MoneyAmount],
            'food_package.event_menu_item_ids' => ['required_with:food_package', 'array', 'min:1', 'max:100'],
            'food_package.event_menu_item_ids.*' => ['required', 'string', 'distinct', Rule::exists('restaurant_event_menu_items', 'id')],
            'food_package.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function foodPackageMessages(): array
    {
        $max = FoodPackageFormulas::MAX_GUESTS;

        return [
            'food_package.required_array_keys' => 'A food package needs a name, guest count, price per head and at least one event menu item.',
            'food_package.name.required_with' => 'Give the food package a name.',
            'food_package.guest_count.required_with' => 'Enter the number of guests for the food package.',
            'food_package.guest_count.integer' => 'The guest count must be a whole number.',
            'food_package.guest_count.between' => "The guest count must be between 1 and {$max}.",
            'food_package.price_per_head.required_with' => 'Enter the price per head.',
            'food_package.event_menu_item_ids.required_with' => 'Select at least one event menu item.',
            'food_package.event_menu_item_ids.min' => 'Select at least one event menu item.',
            'food_package.event_menu_item_ids.*.exists' => 'This event menu item is not available.',
            'food_package.event_menu_item_ids.*.distinct' => 'This item is already in the package.',
        ];
    }
}
