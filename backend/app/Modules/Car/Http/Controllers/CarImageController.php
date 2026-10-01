<?php

namespace App\Modules\Car\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Car\Actions\DeleteCarImage;
use App\Modules\Car\Actions\UploadCarImage;
use App\Modules\Car\Http\Requests\UploadCarImageRequest;
use App\Modules\Car\Http\Resources\CarImageResource;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarImage;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Image routes use scoped bindings: {image} must belong to {car}.
 */
class CarImageController extends Controller
{
    public function store(UploadCarImageRequest $request, Car $car, UploadCarImage $upload): JsonResponse
    {
        $image = $upload->handle($request->user(), $car, $request->file('image'));

        return ApiResponse::success(CarImageResource::make($image)->resolve(), 'Image uploaded successfully.', 201);
    }

    /**
     * Streams the file to authorized users only (files are never publicly addressable).
     */
    public function file(Car $car, CarImage $image): StreamedResponse
    {
        Gate::authorize('view', $car);

        $disk = Storage::disk($image->disk);
        if (! $disk->exists($image->path)) {
            throw new NotFoundHttpException;
        }

        return $disk->response($image->path, null, [
            'Content-Type' => $image->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, Car $car, CarImage $image, DeleteCarImage $deleteImage): JsonResponse
    {
        Gate::authorize('update', $car);

        $deleteImage->handle($request->user(), $image);

        return ApiResponse::success(message: 'Image deleted successfully.');
    }
}
