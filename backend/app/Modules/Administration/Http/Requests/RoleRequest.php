<?php

namespace App\Modules\Administration\Http\Requests;

use App\Modules\Identity\Models\Role;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and update (PUT/PATCH) share rules; updates accept partial payloads.
 */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->isMethod('POST') ? 'role.create' : 'role.update');
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        /** @var Role|null $role */
        $role = $this->route('role');

        return [
            'name' => ['bail', $required, 'string', 'max:100', 'regex:/\S/', $this->uniqueName($role)],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => [$this->isMethod('POST') ? 'present' : 'sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')],
        ];
    }

    /**
     * Role names are unique case-insensitively (mirrors the roles_name_lower_unique index).
     */
    private function uniqueName(?Role $ignore): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore) {
            $taken = Role::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->getKey()))
                ->exists();

            if ($taken) {
                $fail('The role name has already been taken.');
            }
        };
    }
}
