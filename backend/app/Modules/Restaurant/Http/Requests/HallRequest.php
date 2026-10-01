<?php

namespace App\Modules\Restaurant\Http\Requests;

use App\Modules\Branch\Rules\AccessibleBranch;
use App\Modules\Restaurant\Models\Hall;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Halls belong to a branch the user can access; names are unique within a branch.
 * A hall with bookings cannot move to another branch.
 */
class HallRequest extends MasterDataRequest
{
    protected function permissionPrefix(): string
    {
        return 'restaurant.hall';
    }

    protected function routeKey(): string
    {
        return 'hall';
    }

    private function current(): ?Hall
    {
        return $this->route('hall');
    }

    public function authorize(): bool
    {
        return $this->current() === null
            ? $this->user()->can('create', Hall::class)
            : $this->user()->can('update', $this->current());
    }

    public function rules(): array
    {
        return [
            'branch_id' => [$this->required(), 'string', new AccessibleBranch, Rule::exists('branches', 'id')->where('is_active', true)],
            'name' => [$this->required(), 'string', 'max:100'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'between:1,100000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['name', 'branch_id'])) {
                return;
            }

            $branchId = $this->input('branch_id', $this->current()?->branch_id);
            $name = $this->input('name', $this->current()?->name);

            $taken = DB::table('restaurant_halls')
                ->where('branch_id', $branchId)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->when($this->current(), fn ($q, $hall) => $q->where('id', '!=', $hall->id))
                ->exists();

            if ($taken) {
                $validator->errors()->add('name', 'This branch already has a hall with this name.');
            }
            if ($this->current() && $branchId !== $this->current()->branch_id && $this->current()->bookings()->exists()) {
                $validator->errors()->add('branch_id', 'This hall has bookings and cannot be moved to another branch.');
            }
        }];
    }

    public function messages(): array
    {
        return ['branch_id.exists' => 'The selected branch is invalid.'];
    }
}
