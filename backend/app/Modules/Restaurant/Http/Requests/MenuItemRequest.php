<?php

namespace App\Modules\Restaurant\Http\Requests;

use App\Modules\Restaurant\Models\MenuItem;
use App\Modules\Shared\Rules\MoneyAmount;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Menu item: price is a decimal string converted to integer minor units.
 * New items (or items moved to another category) need an active category;
 * names are unique within a category.
 */
class MenuItemRequest extends MasterDataRequest
{
    protected function permissionPrefix(): string
    {
        return 'restaurant.menu';
    }

    protected function routeKey(): string
    {
        return 'menuItem';
    }

    private function current(): ?MenuItem
    {
        return $this->route($this->routeKey());
    }

    public function rules(): array
    {
        $categoryChanges = $this->current() === null || $this->input('category_id') !== $this->current()->category_id;

        return [
            'category_id' => [$this->required(), 'string', Rule::exists('restaurant_menu_categories', 'id')
                ->when($categoryChanges, fn ($rule) => $rule->where('is_active', true))],
            'name' => [$this->required(), 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price' => [$this->required(), new MoneyAmount],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** Name must be unique within the (possibly new) category, also when only the category changes. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['name', 'category_id'])) {
                return;
            }

            $name = $this->input('name', $this->current()?->name);
            $categoryId = $this->input('category_id', $this->current()?->category_id);

            $taken = DB::table('restaurant_menu_items')
                ->where('category_id', $categoryId)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->when($this->ignoreId(), fn ($q, $id) => $q->where('id', '!=', $id))
                ->exists();

            if ($taken) {
                $validator->errors()->add('name', 'A menu item with this name already exists in the category.');
            }
        }];
    }

    public function messages(): array
    {
        return parent::messages() + ['category_id.exists' => 'Select an active menu category.'];
    }

    /** Validated input mapped to model attributes (price → price_minor). */
    public function record(): array
    {
        $data = $this->validated();

        if (array_key_exists('price', $data)) {
            $data['price_minor'] = Money::toMinor($data['price']);
        }

        return Arr::except($data, 'price');
    }
}
