<?php

namespace App\Modules\Restaurant\Http\Requests;

/**
 * Customer names may repeat (different people); the phone number identifies duplicates.
 */
class CustomerRequest extends MasterDataRequest
{
    protected function permissionPrefix(): string
    {
        return 'restaurant.customer';
    }

    protected function routeKey(): string
    {
        return 'customer';
    }

    public function rules(): array
    {
        return [
            'name' => [$this->required(), 'string', 'max:150'],
            'phone' => $this->phoneRules('restaurant_customers'),
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
