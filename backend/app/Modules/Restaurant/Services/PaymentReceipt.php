<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Administration\Services\BusinessProfiles;
use App\Modules\Branch\Models\Branch;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Models\FoodSalePayment;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Models\HallBookingPayment;
use App\Modules\Restaurant\Models\RestaurantExpense;
use App\Modules\Restaurant\Models\RestaurantExpensePayment;
use App\Modules\Restaurant\Support\PaymentFormulas;
use App\Modules\Shared\Support\AmountInWords;
use App\Modules\Shared\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Printable payment documents: food sale receipts in 80 mm POS (thermal roll) format; hall booking receipts and
 * supplier payment vouchers on A4.
 * Figures come from the stored records via PaymentFormulas; the balance shown is the position
 * right after this payment (active payments recorded up to and including it).
 */
class PaymentReceipt
{
    public function __construct(private readonly BusinessProfiles $profiles) {}

    /** 80 mm thermal roll width in points (1 mm = 2.8346 pt). */
    public const POS_WIDTH_PT = 226.77;

    /** Bottom page margin of the POS template (4 mm) plus a little paper to tear off. */
    private const POS_BOTTOM_PT = 11.34 + 8;

    public function forSalePayment(FoodSale $sale, FoodSalePayment $payment): Response
    {
        $data = $this->saleReceiptData($sale, $payment);

        return Pdf::loadView('restaurant.pos-receipt', $data)
            ->setOption('isFontSubsettingEnabled', true)
            ->setPaper([0, 0, self::POS_WIDTH_PT, $this->posHeight($data)])
            ->stream($data['number'].'.pdf');
    }

    /** Hall booking receipts are A4 documents (like all vouchers/invoices except the POS slip). */
    public function forBookingPayment(HallBooking $booking, HallBookingPayment $payment): Response
    {
        return $this->pdf($this->bookingReceiptData($booking, $payment));
    }

    /** Supplier payment voucher (money paid to a supplier against a bill), A4. */
    public function forExpensePayment(RestaurantExpense $expense, RestaurantExpensePayment $payment): Response
    {
        return $this->pdf($this->expenseVoucherData($expense, $payment));
    }

    /**
     * Everything printed on a supplier payment voucher.
     *
     * @return array<string, mixed>
     */
    public function expenseVoucherData(RestaurantExpense $expense, RestaurantExpensePayment $payment): array
    {
        $expense->loadMissing(['supplier', 'category']);
        $payment->loadMissing(['recorder:id,name', 'reverser:id,name']);
        $paid = $this->paidUpTo($expense->payments(), $payment);

        return $this->data('SV', $payment, $expense->branch_id, [
            'title' => 'Payment Voucher',
            'number_label' => 'Voucher no.',
            'counterparty_label' => 'Paid to',
            'signatures' => ['Received by (supplier)', 'Authorised by'],
            'customer' => $expense->supplier->name,
            'customer_detail' => collect([$expense->supplier->contact_person, $expense->supplier->phone, $expense->supplier->address])->filter()->implode(' · '),
            'purpose' => 'Payment against supplier bill',
            'document' => array_filter([
                'Expense date' => $expense->expense_date->format('d M Y'),
                'Category' => $expense->category->name,
                'Bill / reference' => $expense->reference,
                'Description' => $expense->description,
            ]),
            'items' => [],
            'obligation_label' => 'Bill amount',
            'obligation' => $expense->amount_minor,
            'paid_to_date' => $paid,
        ]);
    }

    /**
     * Height of the continuous roll page: the receipt is laid out once on a very tall page and measured,
     * so the PDF is exactly one page as long as its content (no blank feed, no second page).
     */
    public function posHeight(array $data): float
    {
        $bottom = 0.0;
        $measure = Pdf::loadView('restaurant.pos-receipt', $data)->setPaper([0, 0, self::POS_WIDTH_PT, 14400]);
        $measure->getDomPDF()->setCallbacks([[
            'event' => 'end_frame',
            'f' => function ($frame) use (&$bottom) {
                [, $y, , $height] = $frame->get_border_box();
                if (is_numeric($y) && is_numeric($height)) {
                    $bottom = max($bottom, (float) $y + (float) $height);
                }
            },
        ]]);
        $measure->render();

        return round($bottom + self::POS_BOTTOM_PT, 2);
    }

    /**
     * Everything printed on a food sale payment receipt.
     *
     * @return array<string, mixed>
     */
    public function saleReceiptData(FoodSale $sale, FoodSalePayment $payment): array
    {
        $sale->loadMissing(['customer', 'items']);
        $payment->loadMissing(['recorder:id,name', 'reverser:id,name']);
        $paid = $this->paidUpTo($sale->payments(), $payment);

        return $this->data('FR', $payment, $sale->branch_id, [
            'customer' => $sale->customer?->name ?? 'Walk-in customer',
            'customer_detail' => $sale->customer ? collect([$sale->customer->phone, $sale->customer->address])->filter()->implode(' · ') : null,
            'purpose' => 'Payment for food sale '.$sale->sale_no,
            'document' => [
                'Sale no.' => $sale->sale_no,
                'Sale time' => $sale->sold_at->format('d M Y H:i'),
            ],
            'items' => $sale->items->map(fn ($item) => [
                'name' => $item->item_name,
                'quantity' => $item->quantity,
                'unit_price' => self::grouped($item->unit_price_minor),
                'line_total' => self::grouped($item->line_total_minor),
            ])->all(),
            'obligation_label' => 'Sale total',
            'obligation' => $sale->total_minor,
            'paid_to_date' => $paid,
        ]);
    }

    /**
     * Everything printed on a hall booking payment receipt.
     *
     * @return array<string, mixed>
     */
    public function bookingReceiptData(HallBooking $booking, HallBookingPayment $payment): array
    {
        $booking->loadMissing(['customer', 'hall', 'foodPackage.items']);
        $payment->loadMissing(['recorder:id,name', 'reverser:id,name']);
        $paid = $this->paidUpTo($booking->payments(), $payment);
        $package = $booking->foodPackage;

        // Booking total = hall charge + food package (as agreed on the booking).
        $charges = [['label' => 'Hall charge', 'amount' => self::grouped($booking->hall_charge_minor)]];
        if ($package) {
            $charges[] = [
                'label' => "Food package: {$package->name} ({$package->guest_count} guests × ".self::grouped($package->price_per_head_minor).')',
                'amount' => self::grouped($package->total_minor),
            ];
        }

        return $this->data('BR', $payment, $booking->branch_id, [
            'customer' => $booking->customer->name,
            'customer_detail' => collect([$booking->customer->phone, $booking->customer->address])->filter()->implode(' · '),
            'purpose' => 'Payment for hall booking '.$booking->booking_no,
            'document' => [
                'Booking no.' => $booking->booking_no,
                'Hall' => $booking->hall->name,
                'Event' => $booking->booking_date->format('d M Y').', '.$booking->startsAt().'–'.$booking->endsAt(),
                'Booking status' => Str::title($booking->status->value),
            ],
            'items' => [],
            'charges' => $charges,
            'package_items' => $package ? $package->items->pluck('item_name')->all() : [],
            'obligation_label' => 'Booking total',
            'obligation' => $booking->agreed_amount_minor,
            'paid_to_date' => $paid,
        ]);
    }

    /** Stable receipt number, e.g. FR-20261002-01ABCD. */
    public static function number(string $prefix, FoodSalePayment|HallBookingPayment|RestaurantExpensePayment $payment): string
    {
        return $prefix.'-'.$payment->payment_date->format('Ymd').'-'.Str::upper(substr($payment->id, -6));
    }

    /** Active payments recorded up to and including this one (a reversed payment does not count). */
    private function paidUpTo(HasMany $payments, FoodSalePayment|HallBookingPayment|RestaurantExpensePayment $payment): int
    {
        return (int) $payments->getQuery()
            ->whereNull('reversed_at')
            ->where(fn ($q) => $q->where('created_at', '<', $payment->created_at)
                ->orWhere(fn ($q) => $q->where('created_at', $payment->created_at)->where('id', '<=', $payment->id)))
            ->sum('amount_minor');
    }

    private function data(string $prefix, FoodSalePayment|HallBookingPayment|RestaurantExpensePayment $payment, string $branchId, array $context): array
    {
        $letterhead = $this->profiles->letterhead('restaurant');

        // Formatted values below replace the raw minor amounts passed in $context.
        return array_merge($context, [
            'number' => self::number($prefix, $payment),
            'business' => $letterhead['name'],
            'business_lines' => $letterhead['lines'],
            'branch' => Branch::find($branchId),
            'date' => $payment->payment_date->format('d M Y'),
            'amount' => self::grouped($payment->amount_minor),
            'amount_words' => AmountInWords::taka($payment->amount_minor),
            'method' => Str::of($payment->method->value)->replace('_', ' ')->title(),
            'reference' => $payment->reference,
            'notes' => $payment->notes,
            'obligation' => self::grouped($context['obligation']),
            'paid_to_date' => self::grouped($context['paid_to_date']),
            'outstanding' => self::grouped(PaymentFormulas::due($context['obligation'], $context['paid_to_date'])),
            'recorded_by' => $payment->recorder?->name,
            'void' => $payment->isReversed() ? [
                'at' => $payment->reversed_at->format('d M Y'),
                'by' => $payment->reverser?->name,
                'reason' => $payment->reversal_reason,
            ] : null,
            'printed_at' => now()->format('d M Y H:i'),
        ]);
    }

    /** Size steps tried (1 = normal) until an A4 receipt fits on a single page. */
    public const PAGE_SCALES = [1.0, 0.92, 0.85, 0.78, 0.72, 0.66, 0.6];

    private function pdf(array $data): Response
    {
        return $this->fittedPage($data)->stream($data['number'].'.pdf');
    }

    /**
     * Renders the A4 receipt on ONE page: normal size when it fits, otherwise everything (text and
     * spacing) is scaled down step by step. Only extremely long receipts at the smallest size use a second page.
     */
    public function fittedPage(array $data): DomPdfWrapper
    {
        foreach (self::PAGE_SCALES as $scale) {
            $pdf = Pdf::loadView('restaurant.payment-receipt', $data + ['scale' => $scale])
                ->setOption('isFontSubsettingEnabled', true)
                ->setPaper('a4', 'portrait');
            $pdf->render();

            if ($pdf->getDomPDF()->getCanvas()->get_page_count() === 1) {
                break;
            }
        }

        return $pdf;
    }

    /** 1234567 (minor) → "12,345.67" using integer/string operations only. */
    private static function grouped(int $minor): string
    {
        [$whole, $fraction] = explode('.', Money::toDecimal($minor));
        $negative = str_starts_with($whole, '-');
        $whole = ltrim($whole, '-');

        return ($negative ? '-' : '').strrev(implode(',', str_split(strrev($whole), 3))).'.'.$fraction;
    }
}
