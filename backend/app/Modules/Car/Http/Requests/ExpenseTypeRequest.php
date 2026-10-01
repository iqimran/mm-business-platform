<?php

namespace App\Modules\Car\Http\Requests;

use App\Modules\Shared\Rules\UniqueCaseInsensitive;

class ExpenseTypeRequest extends MasterDataRequest
{
    protected function permissionPrefix(): string
    {
        return 'car.expense_type';
    }

    protected function routeKey(): string
    {
        return 'expenseType';
    }

    public function rules(): array
    {
        return [
            'name' => [$this->required(), 'string', 'max:100', new UniqueCaseInsensitive('car_expense_types', 'name', $this->ignoreId())],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
