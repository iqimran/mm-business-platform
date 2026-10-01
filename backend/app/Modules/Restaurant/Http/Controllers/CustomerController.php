<?php

namespace App\Modules\Restaurant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Http\Requests\CustomerRequest;
use App\Modules\Restaurant\Http\Resources\CustomerResource;
use App\Modules\Restaurant\Models\RestaurantCustomer;
use App\Modules\Restaurant\Services\MasterRecordService;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Restaurant customers. Shared master data: permission-based, not branch-scoped.
 * No delete: customers will be referenced by sales and bookings, so they are deactivated instead.
 */
class CustomerController extends Controller
{
    private const PERMISSION = 'restaurant.customer';

    private const ENTITY = 'restaurant_customer';

    public function __construct(private readonly MasterRecordService $records) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $records = RestaurantCustomer::query()
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('phone', 'ilike', "%{$search}%")))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($records, CustomerResource::class);
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        $record = $this->records->create($request->user(), RestaurantCustomer::class, self::ENTITY, $request->validated());

        return ApiResponse::success(CustomerResource::make($record)->resolve(), 'Customer created successfully.', 201);
    }

    public function show(RestaurantCustomer $customer): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        return ApiResponse::success(CustomerResource::make($customer)->resolve());
    }

    public function update(CustomerRequest $request, RestaurantCustomer $customer): JsonResponse
    {
        $record = $this->records->update($request->user(), $customer, self::ENTITY, $request->validated());

        return ApiResponse::success(CustomerResource::make($record)->resolve(), 'Customer updated successfully.');
    }
}
