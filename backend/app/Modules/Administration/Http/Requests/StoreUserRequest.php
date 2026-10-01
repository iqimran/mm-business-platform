<?php

namespace App\Modules\Administration\Http\Requests;

use App\Modules\Branch\Rules\AccessibleBranch;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'max:255', Password::defaults()],
            'is_active' => ['sometimes', 'boolean'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['string', 'distinct', Rule::exists('roles', 'id')],
            // Users created by branch-scoped admins must belong to at least one of their branches.
            'branch_ids' => [Rule::requiredIf(! $this->user()->canAccessAllBranches()), 'array'],
            'branch_ids.*' => ['string', 'distinct', new AccessibleBranch],
        ];
    }
}
