<?php

namespace App\Modules\Restaurant\Reports;

use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Enums\BookingStatus;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Services\HallBookingQuery;
use App\Modules\Restaurant\Support\PaymentFormulas;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Hall bookings report by event date. Without a status filter cancelled bookings are left out;
 * totals never include cancelled bookings (their amount is not revenue and nothing is due).
 * Booking total = hall charge + event food package; received/due are for the whole booking.
 */
class BookingsReport extends RestaurantReport
{
    public function __construct(private readonly HallBookingQuery $bookings) {}

    public function permission(): string
    {
        return 'restaurant.booking.view';
    }

    public function exportTitle(string $groupBy): string
    {
        return 'Hall bookings';
    }

    public function filterLabels(): array
    {
        return ['status' => 'Status', 'payment_status' => 'Payment status', 'hall_id' => 'Hall', 'customer_id' => 'Customer'];
    }

    public function exportColumns(string $groupBy): array
    {
        return [
            ['label' => 'Booking no.', 'type' => 'text', 'value' => fn ($r) => $r['booking_no']],
            ['label' => 'Event date', 'type' => 'date', 'value' => fn ($r) => $r['booking_date']],
            ['label' => 'Time', 'type' => 'text', 'value' => fn ($r) => $r['start_time'].'–'.$r['end_time']],
            ['label' => 'Branch', 'type' => 'text', 'value' => fn ($r) => $r['branch']['code']],
            ['label' => 'Hall', 'type' => 'text', 'value' => fn ($r) => $r['hall']],
            ['label' => 'Customer', 'type' => 'text', 'value' => fn ($r) => $r['customer']],
            ['label' => 'Status', 'type' => 'text', 'value' => fn ($r) => $r['status']],
            ['label' => 'Hall charge', 'type' => 'money', 'value' => fn ($r) => $r['hall_charge'], 'total' => fn ($t) => $t['hall_charges']],
            ['label' => 'Food package', 'type' => 'money', 'value' => fn ($r) => $r['food_package'], 'total' => fn ($t) => $t['food_packages']],
            ['label' => 'Booking total', 'type' => 'money', 'value' => fn ($r) => $r['booking_total'], 'total' => fn ($t) => $t['booking_total']],
            ['label' => 'Received', 'type' => 'money', 'value' => fn ($r) => $r['paid'], 'total' => fn ($t) => $t['paid']],
            ['label' => 'Due', 'type' => 'money', 'value' => fn ($r) => $r['due'], 'total' => fn ($t) => $t['due']],
            ['label' => 'Payment status', 'type' => 'text', 'value' => fn ($r) => $r['payment_status']],
        ];
    }

    protected function filterRules(): array
    {
        return [
            'hall_id' => ['sometimes', 'string', 'max:26'],
            'customer_id' => ['sometimes', 'string', 'max:26'],
            'status' => ['sometimes', Rule::enum(BookingStatus::class)],
            'payment_status' => ['sometimes', Rule::in(['unpaid', 'partial', 'paid'])],
        ];
    }

    protected function sortable(string $groupBy): array
    {
        $paid = HallBooking::paidSql();

        return [
            'booking_date' => 'restaurant_hall_bookings.booking_date',
            'booking_no' => 'restaurant_hall_bookings.booking_no',
            'agreed_amount' => 'restaurant_hall_bookings.agreed_amount_minor',
            'booking_total' => 'restaurant_hall_bookings.agreed_amount_minor',
            'hall_charge' => 'restaurant_hall_bookings.hall_charge_minor',
            'paid' => $paid,
            'due' => "(restaurant_hall_bookings.agreed_amount_minor - {$paid})",
        ];
    }

    protected function defaultSort(string $groupBy): string
    {
        return 'booking_date';
    }

    public function run(User $user, Request $request): array
    {
        $input = $this->input($request);
        $filtered = $this->bookings->filtered($user, $input['filters']);
        if (! isset($input['filters']['status'])) {
            $filtered->occupying();
        }

        $summary = $this->bookings->summary($filtered);
        $cancelled = $filtered->clone()->where('restaurant_hall_bookings.status', BookingStatus::Cancelled->value)->count();

        $page = $filtered->clone()
            ->withPaid()
            ->with(['branch:id,name,code', 'hall:id,name', 'customer:id,name', 'foodPackage'])
            ->orderByRaw("{$this->sortable('')[$input['sort']]} {$input['direction']}")
            ->orderBy('restaurant_hall_bookings.start_time', $input['direction'])
            ->orderBy('restaurant_hall_bookings.id', 'desc');
        $page = $this->fetch($page, $input['per_page']);

        return self::paginated($page, function (HallBooking $b) {
            $cancelled = $b->status === BookingStatus::Cancelled;

            return [
                'id' => $b->id,
                'booking_no' => $b->booking_no,
                'booking_date' => $b->booking_date->toDateString(),
                'start_time' => $b->startsAt(),
                'end_time' => $b->endsAt(),
                'status' => $b->status->value,
                'branch' => $b->branch->only('id', 'code', 'name'),
                'hall' => $b->hall->name,
                'customer' => $b->customer->name,
                'hall_charge' => self::money($b->hall_charge_minor),
                'food_package' => $b->foodPackage ? self::money($b->foodPackage->total_minor) : null,
                'food_package_name' => $b->foodPackage?->name,
                'food_package_guests' => $b->foodPackage?->guest_count,
                'booking_total' => self::money($b->agreed_amount_minor),
                'agreed_amount' => self::money($b->agreed_amount_minor),
                'paid' => self::money($b->paid_minor),
                // A cancelled booking owes nothing.
                'due' => self::money($cancelled ? 0 : PaymentFormulas::due($b->agreed_amount_minor, $b->paid_minor)),
                'payment_status' => $cancelled ? null : PaymentFormulas::status($b->agreed_amount_minor, $b->paid_minor)->value,
            ];
        }, [
            'count' => $summary['count'],
            'hall_charges' => $summary['hall_charges'],
            'food_packages' => $summary['food_packages'],
            'booking_total' => $summary['booking_total'],
            'agreed_amount' => $summary['agreed_amount'],
            'paid' => $summary['paid'],
            'due' => $summary['due'],
            'cancelled_count' => $cancelled,
        ], $input);
    }
}
