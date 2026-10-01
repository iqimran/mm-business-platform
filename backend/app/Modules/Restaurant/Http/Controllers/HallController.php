<?php

namespace App\Modules\Restaurant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Http\Requests\HallRequest;
use App\Modules\Restaurant\Http\Resources\HallResource;
use App\Modules\Restaurant\Models\Hall;
use App\Modules\Restaurant\Services\MasterRecordService;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Halls: branch-scoped master data (HallPolicy: permission + branch access).
 */
class HallController extends Controller
{
    private const ENTITY = 'restaurant_hall';

    public function __construct(private readonly MasterRecordService $records) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Hall::class);

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'branch_id' => ['sometimes', 'string', 'max:26'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $records = Hall::query()
            ->accessibleBy($request->user())
            ->with('branch:id,name,code')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('name', 'ilike', "%{$search}%"))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($records, HallResource::class);
    }

    public function store(HallRequest $request): JsonResponse
    {
        $record = $this->records->create($request->user(), Hall::class, self::ENTITY, $request->validated());

        return ApiResponse::success(HallResource::make($record->load('branch'))->resolve(), 'Hall created successfully.', 201);
    }

    public function show(Hall $hall): JsonResponse
    {
        Gate::authorize('view', $hall);

        return ApiResponse::success(HallResource::make($hall->load('branch'))->resolve());
    }

    public function update(HallRequest $request, Hall $hall): JsonResponse
    {
        $record = $this->records->update($request->user(), $hall, self::ENTITY, $request->validated());

        return ApiResponse::success(HallResource::make($record->load('branch'))->resolve(), 'Hall updated successfully.');
    }

    public function destroy(Request $request, Hall $hall): JsonResponse
    {
        Gate::authorize('delete', $hall);

        $this->records->delete($request->user(), $hall, self::ENTITY, $hall->bookings()->exists()
            ? 'This hall has bookings. Deactivate it instead.'
            : null);

        return ApiResponse::success(message: 'Hall deleted successfully.');
    }
}
