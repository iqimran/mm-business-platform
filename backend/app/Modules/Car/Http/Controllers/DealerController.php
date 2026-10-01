<?php

namespace App\Modules\Car\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Car\Http\Requests\DealerRequest;
use App\Modules\Car\Http\Resources\DealerResource;
use App\Modules\Car\Models\CarDealer;
use App\Modules\Car\Services\MasterRecordService;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Car dealers (suppliers). Shared master data: permission-based, not branch-scoped.
 */
class DealerController extends Controller
{
    private const PERMISSION = 'car.dealer';

    private const ENTITY = 'car_dealer';

    public function __construct(private readonly MasterRecordService $records) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $records = CarDealer::query()
            ->withCount('cars')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('phone', 'ilike', "%{$search}%")))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($records, DealerResource::class);
    }

    public function store(DealerRequest $request): JsonResponse
    {
        $record = $this->records->create($request->user(), CarDealer::class, self::ENTITY, $request->validated());

        return ApiResponse::success(DealerResource::make($record)->resolve(), 'Dealer created successfully.', 201);
    }

    public function show(CarDealer $dealer): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        return ApiResponse::success(DealerResource::make($dealer->loadCount('cars'))->resolve());
    }

    public function update(DealerRequest $request, CarDealer $dealer): JsonResponse
    {
        $record = $this->records->update($request->user(), $dealer, self::ENTITY, $request->validated());

        return ApiResponse::success(DealerResource::make($record)->resolve(), 'Dealer updated successfully.');
    }

    public function destroy(Request $request, CarDealer $dealer): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.delete');

        $this->records->delete($request->user(), $dealer, self::ENTITY, $dealer->cars()->exists()
            ? 'This dealer is linked to cars. Deactivate the dealer instead.'
            : null);

        return ApiResponse::success(message: 'Dealer deleted successfully.');
    }
}
