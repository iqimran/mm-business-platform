<?php

namespace App\Modules\Restaurant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Http\Requests\EventMenuItemRequest;
use App\Modules\Restaurant\Http\Resources\EventMenuItemResource;
use App\Modules\Restaurant\Models\EventMenuItem;
use App\Modules\Restaurant\Services\MasterRecordService;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Event menu items for hall booking food packages (no prices). Shared master data: permission-based.
 */
class EventMenuItemController extends Controller
{
    private const PERMISSION = 'restaurant.event_menu';

    private const ENTITY = 'restaurant_event_menu_item';

    public function __construct(private readonly MasterRecordService $records) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $records = EventMenuItem::query()
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('name', 'ilike', "%{$search}%"))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($records, EventMenuItemResource::class);
    }

    public function store(EventMenuItemRequest $request): JsonResponse
    {
        $record = $this->records->create($request->user(), EventMenuItem::class, self::ENTITY, $request->validated());

        return ApiResponse::success(EventMenuItemResource::make($record)->resolve(), 'Event menu item created successfully.', 201);
    }

    public function show(EventMenuItem $eventMenuItem): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        return ApiResponse::success(EventMenuItemResource::make($eventMenuItem)->resolve());
    }

    public function update(EventMenuItemRequest $request, EventMenuItem $eventMenuItem): JsonResponse
    {
        $record = $this->records->update($request->user(), $eventMenuItem, self::ENTITY, $request->validated());

        return ApiResponse::success(EventMenuItemResource::make($record)->resolve(), 'Event menu item updated successfully.');
    }

    public function destroy(Request $request, EventMenuItem $eventMenuItem): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.delete');

        $this->records->delete($request->user(), $eventMenuItem, self::ENTITY, $eventMenuItem->packageItems()->exists()
            ? 'This item is part of a hall booking food package. Mark it inactive instead.'
            : null);

        return ApiResponse::success(message: 'Event menu item deleted successfully.');
    }
}
