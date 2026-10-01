<?php

namespace App\Modules\Car\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Car\Enums\CarDocumentType;
use App\Modules\Car\Http\Resources\CarDocumentResource;
use App\Modules\Car\Models\CarDocument;
use App\Modules\Car\Services\DocumentExpiry;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Expiry alerts across cars of the user's accessible branches (current documents only).
 */
class DocumentExpiryController extends Controller
{
    public function __construct(private readonly DocumentExpiry $expiry) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('car.document.view');

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['alerts', 'expired', 'expiring', 'valid'])],
            'type' => ['sometimes', Rule::enum(CarDocumentType::class)],
            'branch_id' => ['sometimes', 'string', 'max:26'],
        ]);

        $query = CarDocument::query()
            ->current()
            ->accessibleBy($request->user())
            ->with(['car:id,branch_id,brand,model,chassis_number,registration_number', 'car.branch:id,code,name'])
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->whereHas('car', fn ($c) => $c->where('branch_id', $id)));

        $documents = $this->expiry->applyStatus($query, $filters['status'] ?? 'alerts')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($documents, CarDocumentResource::class);
    }

    /**
     * Counts for the dashboard.
     */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('car.document.view');

        $count = fn (string $status) => $this->expiry
            ->applyStatus(CarDocument::query()->current()->accessibleBy($request->user()), $status)
            ->count();

        return ApiResponse::success([
            'expired' => $count('expired'),
            'expiring' => $count('expiring'),
            'alert_days' => $this->expiry->alertDays(),
        ]);
    }
}
