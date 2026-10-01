<?php

namespace App\Modules\Car\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Party names may repeat (different people); phone and national ID identify duplicates.
 */
class PartyRequest extends MasterDataRequest
{
    protected function permissionPrefix(): string
    {
        return 'car.party';
    }

    protected function routeKey(): string
    {
        return 'party';
    }

    public function rules(): array
    {
        return [
            'name' => [$this->required(), 'string', 'max:150'],
            'phone' => $this->phoneRules('car_parties'),
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'national_id' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('car_parties', 'national_id')->ignore($this->ignoreId())],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + ['national_id.unique' => 'This national ID is already registered.'];
    }
}
