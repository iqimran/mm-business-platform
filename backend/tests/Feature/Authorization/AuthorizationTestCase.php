<?php

namespace Tests\Feature\Authorization;

use App\Modules\Branch\Concerns\BelongsToBranch;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Policies\BranchScopedPolicy;
use App\Modules\Branch\Rules\AccessibleBranch;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Provides test-only routes, a branch-scoped model and its policy, mirroring how
 * business modules will use the authorization building blocks.
 */
abstract class AuthorizationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Transactional DDL: rolled back with the test.
        Schema::create('test_branch_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('branch_id')->constrained('branches');
            $table->string('title');
        });

        Gate::policy(TestBranchRecord::class, TestBranchRecordPolicy::class);

        Route::middleware(['api', 'auth:sanctum', 'active'])->prefix('api/v1/__test')->group(function () {
            Route::get('reports', fn () => ApiResponse::success())->middleware('can:test.report.view');

            Route::get('branches/{branch}/dashboard', fn (string $branch) => ApiResponse::success(['branch' => $branch]))
                ->middleware('branch.access');

            Route::get('records', fn (Request $request) => ApiResponse::success(
                TestBranchRecord::accessibleBy($request->user())->orderBy('title')->pluck('title'),
            ))->middleware('can:test.record.view');

            Route::get('records/{record}', function (string $record) {
                $model = TestBranchRecord::findOrFail($record);
                Gate::authorize('view', $model);

                return ApiResponse::success(['title' => $model->title]);
            });

            Route::post('records', function (Request $request) {
                Gate::authorize('test.record.create');
                $data = $request->validate([
                    'branch_id' => ['required', 'string', new AccessibleBranch],
                    'title' => ['required', 'string'],
                ]);

                return ApiResponse::success(TestBranchRecord::create($data)->only('id', 'branch_id'), status: 201);
            });
        });
    }

    protected function userWithPermissions(array $permissions, array $branches = [], string $roleName = 'Tester'): User
    {
        $role = Role::factory()->create(['name' => $roleName.' '.uniqid()]);
        $role->permissions()->attach(collect($permissions)->map(
            fn (string $name) => Permission::firstOrCreate(['name' => $name])->id,
        ));

        $user = User::factory()->create();
        $user->roles()->attach($role);
        $user->branches()->attach(collect($branches)->pluck('id'));

        return $user;
    }

    protected function record(Branch $branch, string $title): TestBranchRecord
    {
        return TestBranchRecord::create(['branch_id' => $branch->id, 'title' => $title]);
    }
}

class TestBranchRecord extends Model
{
    use BelongsToBranch, HasUlids;

    protected $table = 'test_branch_records';

    public $timestamps = false;

    protected $fillable = ['branch_id', 'title'];
}

class TestBranchRecordPolicy extends BranchScopedPolicy
{
    public function view(User $user, TestBranchRecord $record): bool
    {
        return $this->allows($user, 'test.record.view', $record);
    }
}
