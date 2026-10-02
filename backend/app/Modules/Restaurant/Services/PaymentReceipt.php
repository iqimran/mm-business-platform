<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Administration\Services\BusinessProfiles;
use App\Modules\Branch\Models\Branch;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Models\FoodSalePayment;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Models\HallBookingPayment;
use App\Modules\Restaurant\Support\PaymentFormulas;
use App\Modules\Shared\Support\AmountInWords;
use App\Modules\Shared\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Printable money receipts for food sale payments and hall booking payments (PDF, A5).
 * Figures come from the stored records via PaymentFormulas; the balance shown is the position
 * right after this payment (active payments recorded up to and including it).
 */
class PaymentReceipt
{
    public function __construct(private readonly BusinessProfiles $profiles) {}

    public function forSalePayment(FoodSale $sale, FoodSalePayment $payment): Response
    {
        return $this->pdf($this->saleReceiptData($sale, $payment));
    }

    public function forBookingPayment(HallBooking $booking, HallBookingPayment $payment): Response
    {
        return $this->pdf($this->bookingReceiptData($booking, $payment));
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
        $booking->loadMissing(['customer', 'hall']);
        $payment->loadMissing(['recorder:id,name', 'reverser:id,name']);
        $paid = $this->paidUpTo($booking->payments(), $payment);

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
            'obligation_label' => 'Booking amount',
            'obligation' => $booking->agreed_amount_minor,
            'paid_to_date' => $paid,
        ]);
    }

    /** Stable receipt number, e.g. FR-20261002-01ABCD. */
    public static function number(string $prefix, FoodSalePayment|HallBookingPayment $payment): string
    {
        return $prefix.'-'.$payment->payment_date->format('Ymd').'-'.Str::upper(substr($payment->id, -6));
    }

    /** Active payments recorded up to and including this one (a reversed payment does not count). */
    private function paidUpTo(HasMany $payments, FoodSalePayment|HallBookingPayment $payment): int
    {
        return (int) $payments->getQuery()
            ->whereNull('reversed_at')
            ->where(fn ($q) => $q->where('created_at', '<', $payment->created_at)
                ->orWhere(fn ($q) => $q->where('created_at', $payment->created_at)->where('id', '<=', $payment->id)))
            ->sum('amount_minor');
    }

    private function data(string $prefix, FoodSalePayment|HallBookingPayment $payment, string $branchId, array $context): array
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

    private function pdf(array $data): Response
    {
        return Pdf::loadView('restaurant.payment-receipt', $data)
            ->setOption('isFontSubsettingEnabled', true)
            ->setPaper('a5', 'portrait')
            ->stream($data['number'].'.pdf');
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
