<?php

namespace App\Modules\Restaurant\Http\Requests;

use App\Modules\Shared\Rules\UniqueCaseInsensitive;

class MenuCategoryRequest extends MasterDataRequest
{
    protected function permissionPrefix(): string
    {
        return 'restaurant.menu_category';
    }

    protected function routeKey(): string
    {
        return 'menuCategory';
    }

    public function rules(): array
    {
        return [
            'name' => [$this->required(), 'string', 'max:100', new UniqueCaseInsensitive('restaurant_menu_categories', 'name', $this->ignoreId())],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'between:0,9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
