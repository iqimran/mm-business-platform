<?php

namespace App\Modules\Car\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Car\Actions\DeleteCarDocument;
use App\Modules\Car\Actions\SaveCarDocument;
use App\Modules\Car\Http\Requests\CarDocumentRequest;
use App\Modules\Car\Http\Resources\CarDocumentResource;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarDocument;
use App\Modules\Car\Services\DocumentExpiry;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CarDocumentController extends Controller
{
    /**
     * All documents of a car (current and superseded), newest expiry first per type.
     */
    public function index(Car $car, DocumentExpiry $expiry): JsonResponse
    {
        Gate::authorize('viewDocuments', $car);

        $currentIds = $car->documents()->current()->pluck('id')->flip();
        $documents = $car->documents()->with('recorder:id,name')
            ->orderBy('type')->orderBy('custom_name')->orderByDesc('expiry_date')
            ->get()
            ->each(fn (CarDocument $d) => $d->setAttribute('is_current', $currentIds->has($d->id)));

        return ApiResponse::success([
            'items' => CarDocumentResource::collection($documents)->resolve(),
            'alert_days' => $expiry->alertDays(),
        ]);
    }

    public function store(CarDocumentRequest $request, Car $car, SaveCarDocument $save): JsonResponse
    {
        $document = $save->handle($request->user(), $car, $request->validated());

        return ApiResponse::success(CarDocumentResource::make($document->load('recorder:id,name'))->resolve(), 'Document added successfully.', 201);
    }

    public function update(CarDocumentRequest $request, Car $car, CarDocument $document, SaveCarDocument $save): JsonResponse
    {
        $document = $save->handle($request->user(), $car, $request->validated(), $document);

        return ApiResponse::success(CarDocumentResource::make($document->load('recorder:id,name'))->resolve(), 'Document updated successfully.');
    }

    public function destroy(Request $request, Car $car, CarDocument $document, DeleteCarDocument $delete): JsonResponse
    {
        Gate::authorize('deleteDocument', $car);

        $delete->handle($request->user(), $document);

        return ApiResponse::success(message: 'Document deleted successfully.');
    }
}
