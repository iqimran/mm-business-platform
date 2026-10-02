<?php

namespace App\Modules\Car\Services;

use App\Modules\Administration\Services\BusinessProfiles;
use App\Modules\Branch\Models\Branch;
use App\Modules\Car\Models\CarDealerPayment;
use App\Modules\Car\Models\CarPartyPayment;
use App\Modules\Shared\Support\AmountInWords;
use App\Modules\Shared\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Printable money receipt (customer payment) and payment voucher (dealer payment).
 * All figures come from the payment record and CarFinancials; nothing is recalculated here.
 */
class PaymentSlip
{
    public function __construct(
        private readonly CarFinancials $financials,
        private readonly BusinessProfiles $profiles,
    ) {}

    public function partyReceipt(CarPartyPayment $payment): Response
    {
        $payment->loadMissing(['sale.party', 'car', 'recorder:id,name', 'reverser:id,name']);
        $position = $this->financials->partyPositionAt($payment);
        $party = $payment->sale->party;

        return $this->render('Money Receipt', 'MR', $payment, [
            'counterparty_label' => 'Received with thanks from',
            'counterparty' => $party->name,
            'counterparty_detail' => collect([$party->phone, $party->address])->filter()->implode(' · '),
            'purpose' => 'Payment against purchase of the car below',
            'obligation_label' => 'Sale price',
            'paid_label' => 'Total received to date',
            'outstanding_label' => 'Balance due',
            'signatures' => ['Customer', 'Received by'],
        ], $position);
    }

    public function dealerVoucher(CarDealerPayment $payment): Response
    {
        $payment->loadMissing(['purchase.dealer', 'car', 'recorder:id,name', 'reverser:id,name']);
        $position = $this->financials->dealerPositionAt($payment);
        $dealer = $payment->purchase->dealer;

        return $this->render('Payment Voucher', 'PV', $payment, [
            'counterparty_label' => 'Paid to',
            'counterparty' => $dealer->name,
            'counterparty_detail' => collect([$dealer->phone, $dealer->address])->filter()->implode(' · '),
            'purpose' => 'Payment against purchase of the car below',
            'obligation_label' => 'Purchase price',
            'paid_label' => 'Total paid to date',
            'outstanding_label' => 'Balance payable',
            'signatures' => ['Received by (dealer)', 'Authorised by'],
        ], $position);
    }

    /** Human-friendly, stable document number derived from the payment record. */
    public static function number(string $prefix, CarPartyPayment|CarDealerPayment $payment): string
    {
        return $prefix.'-'.$payment->payment_date->format('Ymd').'-'.Str::upper(substr($payment->id, -6));
    }

    private function render(string $title, string $prefix, CarPartyPayment|CarDealerPayment $payment, array $labels, array $position): Response
    {
        $car = $payment->car;
        $branch = Branch::find($payment->branch_id);
        $letterhead = $this->profiles->letterhead('car');

        $data = $labels + [
            'title' => $title,
            'number' => self::number($prefix, $payment),
            'business' => $letterhead['name'],
            'business_lines' => $letterhead['lines'],
            'branch' => $branch,
            'date' => $payment->payment_date->format('d M Y'),
            'amount' => self::grouped(Money::toDecimal($payment->amount_minor)),
            'amount_words' => AmountInWords::taka($payment->amount_minor),
            'method' => Str::of($payment->method->value)->replace('_', ' ')->title(),
            'reference' => $payment->reference,
            'notes' => $payment->notes,
            'car' => trim("{$car->brand} {$car->model} {$car->model_year}"),
            'chassis' => $car->chassis_number,
            'registration' => $car->registration_number,
            'obligation' => self::grouped(Money::toDecimal($position['obligation'])),
            'paid_to_date' => self::grouped(Money::toDecimal($position['paid_to_date'])),
            'outstanding' => self::grouped(Money::toDecimal($position['outstanding'])),
            'recorded_by' => $payment->recorder?->name,
            'void' => $payment->isReversed() ? [
                'at' => $payment->reversed_at->format('d M Y'),
                'by' => $payment->reverser?->name,
                'reason' => $payment->reversal_reason,
            ] : null,
            'printed_at' => now()->format('d M Y H:i'),
        ];

        return Pdf::loadView('slips.payment-slip', $data)
            ->setOption('isFontSubsettingEnabled', true)
            ->setPaper('a5', 'portrait')
            ->stream($data['number'].'.pdf');
    }

    private static function grouped(string $decimal): string
    {
        [$whole, $fraction] = explode('.', $decimal);

        return strrev(implode(',', str_split(strrev($whole), 3))).'.'.$fraction;
    }
}
