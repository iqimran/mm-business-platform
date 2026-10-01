<?php

namespace App\Modules\Car\Http\Requests;

use App\Modules\Shared\Rules\UniqueCaseInsensitive;

class DealerRequest extends MasterDataRequest
{
    protected function permissionPrefix(): string
    {
        return 'car.dealer';
    }

    protected function routeKey(): string
    {
        return 'dealer';
    }

    public function rules(): array
    {
        return [
            'name' => [$this->required(), 'string', 'max:150', new UniqueCaseInsensitive('car_dealers', 'name', $this->ignoreId())],
            'phone' => $this->phoneRules('car_dealers'),
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
