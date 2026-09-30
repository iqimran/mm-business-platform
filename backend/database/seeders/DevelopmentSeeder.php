<?php

namespace Database\Seeders;

use App\Modules\Branch\Models\Branch;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo data: development admin, demo branches and a demo user.
 * Refuses to run outside local/testing. Credentials come from the environment.
 */
class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DevelopmentSeeder may only run in local or testing environments.');
        }

        $branches = collect([
            ['code' => 'HQ', 'name' => 'Head Office'],
            ['code' => 'BR-02', 'name' => 'Second Branch'],
        ])->map(fn (array $branch) => Branch::firstOrCreate(['code' => $branch['code']], $branch));

        $password = env('SEED_ADMIN_PASSWORD', 'password');

        $admin = User::firstOrCreate(
            ['email' => env('SEED_ADMIN_EMAIL', 'admin@example.com')],
            ['name' => 'Development Admin', 'password' => $password, 'email_verified_at' => now()],
        );
        $admin->roles()->syncWithoutDetaching(Role::where('name', RoleSeeder::SUPER_ADMIN)->pluck('id'));
        $admin->branches()->syncWithoutDetaching($branches->pluck('id'));

        $demo = User::firstOrCreate(
            ['email' => 'user@example.com'],
            ['name' => 'Demo User', 'password' => $password, 'email_verified_at' => now()],
        );
        $demo->roles()->syncWithoutDetaching(Role::where('name', 'General User')->pluck('id'));
        $demo->branches()->syncWithoutDetaching([$branches->first()->id]);
    }
}
