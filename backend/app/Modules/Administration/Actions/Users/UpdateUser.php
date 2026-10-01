<?php

namespace App\Modules\Administration\Actions\Users;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name?: string, email?: string, password?: string|null, is_active?: bool}  $data
     */
    public function handle(User $actor, User $user, array $data): User
    {
        if ($actor->is($user) && array_key_exists('is_active', $data) && ! $data['is_active']) {
            throw ValidationException::withMessages(['is_active' => 'You cannot deactivate your own account.']);
        }

        return DB::transaction(function () use ($actor, $user, $data) {
            $user->fill(Arr::only($data, ['name', 'email', 'is_active']));

            // An admin-set password signs the user out of existing sessions (password hash changes).
            $passwordChanged = filled($data['password'] ?? null);
            if ($passwordChanged) {
                $user->password = $data['password'];
            }

            $changed = array_keys(Arr::except($user->getDirty(), ['password']));
            $old = Arr::only($user->getOriginal(), $changed);

            $user->save();

            if ($changed !== [] || $passwordChanged) {
                $new = Arr::only($user->getAttributes(), $changed);
                if ($passwordChanged) {
                    $new['password_changed'] = true; // never the value itself
                }

                $this->audit->record('user.updated', 'user', $user->id, $actor->id, oldValues: $old, newValues: $new);
            }

            return $user;
        });
    }
}
