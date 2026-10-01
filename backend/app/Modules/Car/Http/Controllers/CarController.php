<?php

namespace App\Modules\Car\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Car\Actions\CreateCar;
use App\Modules\Car\Actions\DeleteCar;
use App\Modules\Car\Actions\UpdateCar;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Http\Requests\CarRequest;
use App\Modules\Car\Http\Resources\CarResource;
use App\Modules\Car\Models\Car;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Car::class);

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::enum(CarStatus::class)],
            'branch_id' => ['sometimes', 'string', 'max:26'],
            'dealer_id' => ['sometimes', 'string', 'max:26'],
        ]);

        $cars = Car::query()
            ->accessibleBy($request->user()) // branch isolation
            ->with(['branch:id,code,name', 'dealer:id,name'])
            ->withCount('images')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('brand', 'ilike', "%{$search}%")
                ->orWhere('model', 'ilike', "%{$search}%")
                ->orWhere('chassis_number', 'ilike', '%'.strtoupper(preg_replace('/\s+/', '', $search)).'%')
                ->orWhere('registration_number', 'ilike', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['dealer_id'] ?? null, fn ($q, $id) => $q->where('dealer_id', $id))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($cars, CarResource::class);
    }

    public function store(CarRequest $request, CreateCar $createCar): JsonResponse
    {
        $car = $createCar->handle($request->user(), $request->validated());

        return ApiResponse::success($this->present($car), 'Car created successfully.', 201);
    }

    public function show(Car $car): JsonResponse
    {
        Gate::authorize('view', $car);

        return ApiResponse::success($this->present($car));
    }

    public function update(CarRequest $request, Car $car, UpdateCar $updateCar): JsonResponse
    {
        $car = $updateCar->handle($request->user(), $car, $request->validated());

        return ApiResponse::success($this->present($car), 'Car updated successfully.');
    }

    public function destroy(Request $request, Car $car, DeleteCar $deleteCar): JsonResponse
    {
        Gate::authorize('delete', $car);

        $deleteCar->handle($request->user(), $car);

        return ApiResponse::success(message: 'Car deleted successfully.');
    }

    private function present(Car $car): array
    {
        return CarResource::make($car->load(['branch:id,code,name', 'dealer:id,name', 'images']))->resolve();
    }
}
