<?php

namespace App\Modules\Restaurant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Http\Requests\MenuCategoryRequest;
use App\Modules\Restaurant\Http\Resources\MenuCategoryResource;
use App\Modules\Restaurant\Models\MenuCategory;
use App\Modules\Restaurant\Services\MasterRecordService;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Food menu categories. Shared master data: permission-based, not branch-scoped.
 */
class MenuCategoryController extends Controller
{
    private const PERMISSION = 'restaurant.menu_category';

    private const ENTITY = 'restaurant_menu_category';

    public function __construct(private readonly MasterRecordService $records) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $records = MenuCategory::query()
            ->withCount('items')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('name', 'ilike', "%{$search}%"))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($records, MenuCategoryResource::class);
    }

    public function store(MenuCategoryRequest $request): JsonResponse
    {
        $record = $this->records->create($request->user(), MenuCategory::class, self::ENTITY, $request->validated());

        return ApiResponse::success(MenuCategoryResource::make($record->loadCount('items'))->resolve(), 'Menu category created successfully.', 201);
    }

    public function show(MenuCategory $menuCategory): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        return ApiResponse::success(MenuCategoryResource::make($menuCategory->loadCount('items'))->resolve());
    }

    public function update(MenuCategoryRequest $request, MenuCategory $menuCategory): JsonResponse
    {
        $record = $this->records->update($request->user(), $menuCategory, self::ENTITY, $request->validated());

        return ApiResponse::success(MenuCategoryResource::make($record->loadCount('items'))->resolve(), 'Menu category updated successfully.');
    }

    public function destroy(Request $request, MenuCategory $menuCategory): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.delete');

        $this->records->delete($request->user(), $menuCategory, self::ENTITY, $menuCategory->items()->exists()
            ? 'This category has menu items. Move or delete them first, or deactivate the category instead.'
            : null);

        return ApiResponse::success(message: 'Menu category deleted successfully.');
    }
}
