<?php

namespace App\Modules\Restaurant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Actions\ChangeHallBookingStatus;
use App\Modules\Restaurant\Actions\CreateHallBooking;
use App\Modules\Restaurant\Actions\RecordBookingPayment;
use App\Modules\Restaurant\Actions\ReverseBookingPayment;
use App\Modules\Restaurant\Actions\UpdateHallBooking;
use App\Modules\Restaurant\Enums\BookingStatus;
use App\Modules\Restaurant\Http\Requests\BookingReasonRequest;
use App\Modules\Restaurant\Http\Requests\RecordBookingPaymentRequest;
use App\Modules\Restaurant\Http\Requests\StoreHallBookingRequest;
use App\Modules\Restaurant\Http\Requests\UpdateHallBookingRequest;
use App\Modules\Restaurant\Http\Resources\HallBookingResource;
use App\Modules\Restaurant\Models\Hall;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Models\HallBookingPayment;
use App\Modules\Restaurant\Services\HallAvailability;
use App\Modules\Restaurant\Services\HallBookingQuery;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Hall bookings, their status workflow and payments. Authorization: HallBookingPolicy
 * (permission + branch access). Business rules live in the actions.
 */
class HallBookingController extends Controller
{
    private const DETAIL_RELATIONS = [
        'branch:id,name,code', 'hall:id,name,capacity,is_active', 'customer:id,name,phone',
        'payments.recorder:id,name', 'creator:id,name', 'canceller:id,name', 'foodPackage.items',
    ];

    public function index(Request $request, HallBookingQuery $bookings): JsonResponse
    {
        Gate::authorize('viewAny', HallBooking::class);

        $filters = $request->validate([
            'branch_id' => ['sometimes', 'string', 'max:26'],
            'hall_id' => ['sometimes', 'string', 'max:26'],
            'customer_id' => ['sometimes', 'string', 'max:26'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['sometimes', Rule::enum(BookingStatus::class)],
            'payment_status' => ['sometimes', Rule::in(['unpaid', 'partial', 'paid'])],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);

        $filtered = $bookings->filtered($request->user(), $filters);
        $page = $filtered->clone()
            ->withPaid()
            ->with(['branch:id,name,code', 'hall:id,name,capacity,is_active', 'customer:id,name,phone', 'foodPackage'])
            ->orderByDesc('restaurant_hall_bookings.booking_date')
            ->orderByDesc('restaurant_hall_bookings.start_time')
            ->orderByDesc('restaurant_hall_bookings.id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::success([
            'items' => HallBookingResource::collection($page->getCollection())->resolve(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'summary' => $bookings->summary($filtered),
        ]);
    }

    /** Booked (non-cancelled) slots of a hall on a date. */
    public function availability(Request $request, HallAvailability $availability): JsonResponse
    {
        Gate::authorize('viewAny', HallBooking::class);

        $data = $request->validate([
            'hall_id' => ['required', 'string', 'max:26'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $hall = Hall::find($data['hall_id']);
        // Unknown and inaccessible halls look the same.
        abort_if($hall === null || ! $request->user()->canAccessBranch($hall->branch_id), 404);

        return ApiResponse::success([
            'hall_id' => $hall->id,
            'date' => $data['date'],
            'booked' => $availability->bookedOn($hall->id, $data['date'])->map(fn (HallBooking $b) => [
                'id' => $b->id,
                'booking_no' => $b->booking_no,
                'start_time' => $b->startsAt(),
                'end_time' => $b->endsAt(),
                'status' => $b->status->value,
            ])->values()->all(),
        ]);
    }

    public function store(StoreHallBookingRequest $request, CreateHallBooking $create): JsonResponse
    {
        $booking = $create->handle($request->user(), $request->validated());

        return ApiResponse::success($this->detail($booking), 'Hall booked successfully.', 201);
    }

    public function show(HallBooking $booking): JsonResponse
    {
        Gate::authorize('view', $booking);

        return ApiResponse::success($this->detail($booking));
    }

    public function update(UpdateHallBookingRequest $request, HallBooking $booking, UpdateHallBooking $update): JsonResponse
    {
        $update->handle($request->user(), $booking, $request->validated());

        return ApiResponse::success($this->detail($booking), 'Booking updated successfully.');
    }

    public function cancel(BookingReasonRequest $request, HallBooking $booking, ChangeHallBookingStatus $status): JsonResponse
    {
        $status->cancel($request->user(), $booking, $request->validated('reason'));

        return ApiResponse::success($this->detail($booking), 'Booking cancelled successfully.');
    }

    public function complete(Request $request, HallBooking $booking, ChangeHallBookingStatus $status): JsonResponse
    {
        Gate::authorize('update', $booking);

        $status->complete($request->user(), $booking);

        return ApiResponse::success($this->detail($booking), 'Booking marked as completed.');
    }

    public function storePayment(RecordBookingPaymentRequest $request, HallBooking $booking, RecordBookingPayment $record): JsonResponse
    {
        $record->handle($request->user(), $booking, $request->validated());

        return ApiResponse::success($this->detail($booking), 'Payment recorded successfully.', 201);
    }

    public function reversePayment(BookingReasonRequest $request, HallBooking $booking, HallBookingPayment $payment, ReverseBookingPayment $reverse): JsonResponse
    {
        $reverse->handle($request->user(), $booking, $payment, $request->validated('reason'));

        return ApiResponse::success($this->detail($booking), 'Payment reversed successfully.');
    }

    private function detail(HallBooking $booking): array
    {
        $booking = HallBooking::query()->withPaid()->with(self::DETAIL_RELATIONS)->findOrFail($booking->getKey());

        return HallBookingResource::make($booking)->resolve();
    }
}
