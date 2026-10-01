<?php

namespace App\Modules\Restaurant\Http\Requests;

use App\Modules\Shared\Rules\UniqueCaseInsensitive;

class ExpenseCategoryRequest extends MasterDataRequest
{
    protected function permissionPrefix(): string
    {
        return 'restaurant.expense_category';
    }

    protected function routeKey(): string
    {
        return 'expenseCategory';
    }

    public function rules(): array
    {
        return [
            'name' => [$this->required(), 'string', 'max:100', new UniqueCaseInsensitive('restaurant_expense_categories', 'name', $this->ignoreId())],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
