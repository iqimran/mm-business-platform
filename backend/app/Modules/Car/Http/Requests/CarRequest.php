<?php

namespace App\Modules\Car\Http\Requests;

use App\Modules\Branch\Rules\AccessibleBranch;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Support\Normalize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and update (PUT/PATCH) share rules; updates accept partial payloads.
 */
class CarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->isMethod('POST')
            ? $this->user()->can('create', Car::class)
            : $this->user()->can('update', $this->route('car'));
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['chassis_number' => 'identifier', 'engine_number' => 'identifier', 'registration_number' => 'registration'] as $field => $method) {
            if (is_string($this->input($field))) {
                $normalized[$field] = Normalize::$method($this->input($field));
            }
        }
        foreach (['color', 'notes'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = Normalize::text($this->input($field));
            }
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        /** @var Car|null $car */
        $car = $this->route('car');

        $dealerExists = Rule::exists('car_dealers', 'id');
        if ($creating) {
            $dealerExists = $dealerExists->where('is_active', true);
        }

        return [
            // Never trusted from the client: must be a branch the user can access.
            'branch_id' => [$required, 'string', new AccessibleBranch],
            'dealer_id' => ['sometimes', 'nullable', 'string', $dealerExists],
            'brand' => [$required, 'string', 'max:100', 'regex:/\S/'],
            'model' => [$required, 'string', 'max:100', 'regex:/\S/'],
            'model_year' => ['sometimes', 'nullable', 'integer', 'between:1900,'.(now()->year + 1)],
            'color' => ['sometimes', 'nullable', 'string', 'max:50'],
            'chassis_number' => [$required, 'string', 'max:50', Rule::unique('cars', 'chassis_number')->ignore($car)],
            'engine_number' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('cars', 'engine_number')->ignore($car)],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:30', Rule::unique('cars', 'registration_number')->ignore($car)],
            'mileage_km' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2000000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Lifecycle changes happen only through explicit business actions.
            'status' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.prohibited' => 'The car status cannot be changed here.',
            'chassis_number.unique' => 'A car with this chassis number already exists.',
            'engine_number.unique' => 'A car with this engine number already exists.',
            'registration_number.unique' => 'A car with this registration number already exists.',
        ];
    }
}
