<?php

namespace App\Modules\Branch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Actions\CreateBranch;
use App\Modules\Branch\Actions\UpdateBranch;
use App\Modules\Branch\Http\Requests\BranchRequest;
use App\Modules\Branch\Http\Resources\BranchResource;
use App\Modules\Branch\Models\Branch;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Branch::class);

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $branches = Branch::query()
            ->whereIn('id', $request->user()->accessibleBranchIds())
            ->withCount('users')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', '%'.$search.'%')
                ->orWhere('code', 'ilike', '%'.$search.'%')))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('code')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($branches, BranchResource::class);
    }

    public function store(BranchRequest $request, CreateBranch $createBranch): JsonResponse
    {
        $branch = $createBranch->handle($request->user(), $request->validated());

        return ApiResponse::success(BranchResource::make($branch)->resolve(), 'Branch created successfully.', 201);
    }

    public function show(Branch $branch): JsonResponse
    {
        Gate::authorize('view', $branch);

        return ApiResponse::success(BranchResource::make($branch->loadCount('users'))->resolve());
    }

    public function update(BranchRequest $request, Branch $branch, UpdateBranch $updateBranch): JsonResponse
    {
        $branch = $updateBranch->handle($request->user(), $branch, $request->validated());

        return ApiResponse::success(BranchResource::make($branch)->resolve(), 'Branch updated successfully.');
    }
}
