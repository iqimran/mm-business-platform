<?php

namespace App\Modules\Restaurant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Http\Requests\SupplierRequest;
use App\Modules\Restaurant\Http\Resources\SupplierResource;
use App\Modules\Restaurant\Models\RestaurantSupplier;
use App\Modules\Restaurant\Services\MasterRecordService;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Restaurant suppliers. Shared master data: permission-based, not branch-scoped.
 * No delete: suppliers will be referenced by expenses, so they are deactivated instead.
 */
class SupplierController extends Controller
{
    private const PERMISSION = 'restaurant.supplier';

    private const ENTITY = 'restaurant_supplier';

    public function __construct(private readonly MasterRecordService $records) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $records = RestaurantSupplier::query()
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('phone', 'ilike', "%{$search}%")
                ->orWhere('contact_person', 'ilike', "%{$search}%")))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($records, SupplierResource::class);
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        $record = $this->records->create($request->user(), RestaurantSupplier::class, self::ENTITY, $request->validated());

        return ApiResponse::success(SupplierResource::make($record)->resolve(), 'Supplier created successfully.', 201);
    }

    public function show(RestaurantSupplier $supplier): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        return ApiResponse::success(SupplierResource::make($supplier)->resolve());
    }

    public function update(SupplierRequest $request, RestaurantSupplier $supplier): JsonResponse
    {
        $record = $this->records->update($request->user(), $supplier, self::ENTITY, $request->validated());

        return ApiResponse::success(SupplierResource::make($record)->resolve(), 'Supplier updated successfully.');
    }
}
