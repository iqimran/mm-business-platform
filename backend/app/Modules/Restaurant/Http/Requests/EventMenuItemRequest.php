<?php

namespace App\Modules\Restaurant\Http\Requests;

use App\Modules\Shared\Rules\UniqueCaseInsensitive;

class EventMenuItemRequest extends MasterDataRequest
{
    protected function permissionPrefix(): string
    {
        return 'restaurant.event_menu';
    }

    protected function routeKey(): string
    {
        return 'eventMenuItem';
    }

    public function rules(): array
    {
        return [
            'name' => [$this->required(), 'string', 'max:150', new UniqueCaseInsensitive('restaurant_event_menu_items', 'name', $this->ignoreId())],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
