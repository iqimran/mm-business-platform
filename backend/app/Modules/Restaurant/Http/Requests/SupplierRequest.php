<?php

namespace App\Modules\Restaurant\Http\Requests;

use App\Modules\Shared\Rules\UniqueCaseInsensitive;

/**
 * Suppliers are businesses: the name (case-insensitive) and phone identify duplicates.
 */
class SupplierRequest extends MasterDataRequest
{
    protected function permissionPrefix(): string
    {
        return 'restaurant.supplier';
    }

    protected function routeKey(): string
    {
        return 'supplier';
    }

    public function rules(): array
    {
        return [
            'name' => [$this->required(), 'string', 'max:150', new UniqueCaseInsensitive('restaurant_suppliers', 'name', $this->ignoreId())],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:150'],
            'phone' => $this->phoneRules('restaurant_suppliers'),
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
