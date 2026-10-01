<?php

namespace App\Modules\Car\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Car\Http\Requests\PartyRequest;
use App\Modules\Car\Http\Resources\PartyResource;
use App\Modules\Car\Models\CarParty;
use App\Modules\Car\Services\MasterRecordService;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Car parties (customers). Shared master data: permission-based, not branch-scoped.
 */
class PartyController extends Controller
{
    private const PERMISSION = 'car.party';

    private const ENTITY = 'car_party';

    public function __construct(private readonly MasterRecordService $records) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $records = CarParty::query()
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('phone', 'ilike', "%{$search}%")
                ->orWhere('national_id', 'ilike', "%{$search}%")))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($records, PartyResource::class);
    }

    public function store(PartyRequest $request): JsonResponse
    {
        $record = $this->records->create($request->user(), CarParty::class, self::ENTITY, $request->validated());

        return ApiResponse::success(PartyResource::make($record)->resolve(), 'Party created successfully.', 201);
    }

    public function show(CarParty $party): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        return ApiResponse::success(PartyResource::make($party)->resolve());
    }

    public function update(PartyRequest $request, CarParty $party): JsonResponse
    {
        $record = $this->records->update($request->user(), $party, self::ENTITY, $request->validated());

        return ApiResponse::success(PartyResource::make($record)->resolve(), 'Party updated successfully.');
    }

    public function destroy(Request $request, CarParty $party): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.delete');

        $this->records->delete($request->user(), $party, self::ENTITY, null);

        return ApiResponse::success(message: 'Party deleted successfully.');
    }
}
