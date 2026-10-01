<?php

namespace App\Modules\Restaurant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Http\Requests\MenuItemRequest;
use App\Modules\Restaurant\Http\Resources\MenuItemResource;
use App\Modules\Restaurant\Models\MenuItem;
use App\Modules\Restaurant\Services\MasterRecordService;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Food menu items with a global selling price. Shared master data: permission-based, not branch-scoped.
 */
class MenuItemController extends Controller
{
    private const PERMISSION = 'restaurant.menu';

    private const ENTITY = 'restaurant_menu_item';

    public function __construct(private readonly MasterRecordService $records) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'category_id' => ['sometimes', 'string', 'max:26'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $records = MenuItem::query()
            ->with('category:id,name,is_active')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('name', 'ilike', "%{$search}%"))
            ->when($filters['category_id'] ?? null, fn ($q, $categoryId) => $q->where('category_id', $categoryId))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($records, MenuItemResource::class);
    }

    public function store(MenuItemRequest $request): JsonResponse
    {
        $record = $this->records->create($request->user(), MenuItem::class, self::ENTITY, $request->record());

        return ApiResponse::success(MenuItemResource::make($record->load('category'))->resolve(), 'Menu item created successfully.', 201);
    }

    public function show(MenuItem $menuItem): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        return ApiResponse::success(MenuItemResource::make($menuItem->load('category'))->resolve());
    }

    public function update(MenuItemRequest $request, MenuItem $menuItem): JsonResponse
    {
        $record = $this->records->update($request->user(), $menuItem, self::ENTITY, $request->record());

        return ApiResponse::success(MenuItemResource::make($record->load('category'))->resolve(), 'Menu item updated successfully.');
    }

    public function destroy(Request $request, MenuItem $menuItem): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.delete');

        $this->records->delete($request->user(), $menuItem, self::ENTITY);

        return ApiResponse::success(message: 'Menu item deleted successfully.');
    }
}
