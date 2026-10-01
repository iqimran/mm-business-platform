<?php

namespace App\Modules\Car\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Car\Actions\ChangeCarStatus;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Http\Requests\ChangeStatusRequest;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Support\CarLifecycle;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

class CarStatusController extends Controller
{
    public function update(ChangeStatusRequest $request, Car $car, ChangeCarStatus $change): JsonResponse
    {
        $car = $change->handle($request->user(), $car, CarStatus::from($request->validated('status')), $request->validated('reason'));

        return ApiResponse::success([
            'status' => $car->status->value,
            'next_statuses' => array_map(fn (CarStatus $s) => $s->value, CarLifecycle::nextStatuses($car->status)),
        ], 'Car status updated successfully.');
    }
}
