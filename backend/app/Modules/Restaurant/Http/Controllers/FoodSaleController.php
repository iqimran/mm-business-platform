<?php

namespace App\Modules\Restaurant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Actions\RecordFoodSale;
use App\Modules\Restaurant\Actions\RecordSalePayment;
use App\Modules\Restaurant\Actions\ReverseFoodSale;
use App\Modules\Restaurant\Actions\ReverseSalePayment;
use App\Modules\Restaurant\Http\Requests\RecordSalePaymentRequest;
use App\Modules\Restaurant\Http\Requests\ReverseSaleRecordRequest;
use App\Modules\Restaurant\Http\Requests\StoreFoodSaleRequest;
use App\Modules\Restaurant\Http\Resources\FoodSaleResource;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Models\FoodSalePayment;
use App\Modules\Restaurant\Services\FoodSaleQuery;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Food sales and their payments. Authorization: FoodSalePolicy (permission + branch access).
 * Business rules live in the actions; figures in SaleFinancials.
 */
class FoodSaleController extends Controller
{
    private const DETAIL_RELATIONS = ['branch:id,name,code', 'customer:id,name,phone', 'items', 'payments.recorder:id,name', 'recorder:id,name', 'reverser:id,name'];

    public function index(Request $request, FoodSaleQuery $sales): JsonResponse
    {
        Gate::authorize('viewAny', FoodSale::class);

        $filters = $request->validate([
            'branch_id' => ['sometimes', 'string', 'max:26'],
            'customer_id' => ['sometimes', 'string', 'max:26'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'payment_status' => ['sometimes', Rule::in(['unpaid', 'partial', 'paid'])],
            'state' => ['sometimes', Rule::in(['active', 'reversed'])],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);

        $filtered = $sales->filtered($request->user(), $filters);
        $page = $filtered->clone()
            ->withPaid()
            ->with(['branch:id,name,code', 'customer:id,name,phone'])
            ->withCount('items')
            ->orderByDesc('restaurant_sales.sold_at')
            ->orderByDesc('restaurant_sales.id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::success([
            'items' => FoodSaleResource::collection($page->getCollection())->resolve(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'summary' => $sales->summary($filtered),
        ]);
    }

    public function store(StoreFoodSaleRequest $request, RecordFoodSale $record): JsonResponse
    {
        $sale = $record->handle($request->user(), $request->validated());

        return ApiResponse::success($this->detail($sale), 'Sale recorded successfully.', 201);
    }

    public function show(FoodSale $sale): JsonResponse
    {
        Gate::authorize('view', $sale);

        return ApiResponse::success($this->detail($sale));
    }

    public function reverse(ReverseSaleRecordRequest $request, FoodSale $sale, ReverseFoodSale $reverse): JsonResponse
    {
        $reverse->handle($request->user(), $sale, $request->validated('reason'));

        return ApiResponse::success($this->detail($sale), 'Sale reversed successfully.');
    }

    public function storePayment(RecordSalePaymentRequest $request, FoodSale $sale, RecordSalePayment $record): JsonResponse
    {
        $record->handle($request->user(), $sale, $request->validated());

        return ApiResponse::success($this->detail($sale), 'Payment recorded successfully.', 201);
    }

    public function reversePayment(ReverseSaleRecordRequest $request, FoodSale $sale, FoodSalePayment $payment, ReverseSalePayment $reverse): JsonResponse
    {
        $reverse->handle($request->user(), $sale, $payment, $request->validated('reason'));

        return ApiResponse::success($this->detail($sale), 'Payment reversed successfully.');
    }

    private function detail(FoodSale $sale): array
    {
        $sale = FoodSale::query()->withPaid()->with(self::DETAIL_RELATIONS)->findOrFail($sale->getKey());

        return FoodSaleResource::make($sale)->resolve();
    }
}
