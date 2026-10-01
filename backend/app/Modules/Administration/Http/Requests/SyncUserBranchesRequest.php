<?php

namespace App\Modules\Administration\Http\Requests;

use App\Modules\Branch\Rules\AccessibleBranch;
use Illuminate\Foundation\Http\FormRequest;

class SyncUserBranchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assign', $this->route('user'));
    }

    public function rules(): array
    {
        return [
            'branch_ids' => ['present', 'array'],
            'branch_ids.*' => ['string', 'distinct', new AccessibleBranch],
        ];
    }
}
