<?php

namespace App\Modules\Administration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assign', $this->route('user'));
    }

    public function rules(): array
    {
        return [
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['string', 'distinct', Rule::exists('roles', 'id')],
        ];
    }
}
