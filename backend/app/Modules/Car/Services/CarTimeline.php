<?php

namespace App\Modules\Car\Services;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Car\Models\Car;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Collection;

/**
 * Chronological history of a car built from its records: purchase, expenses, sale,
 * payments (each reversal is its own event) and status changes. Only event types the
 * user may view are included.
 */
class CarTimeline
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function for(Car $car, User $user): Collection
    {
        $events = collect();
        $can = fn (string $ability) => $user->can($ability, $car);

        $add = function ($records, string $type, string $dateField, callable $describe) use ($events) {
            foreach ($records as $r) {
                $events->push([
                    // ULIDs encode creation time (ms) and break ties within the same second.
                    'sort_key' => $r->id,
                    'type' => $type,
                    'date' => $r->{$dateField}->toDateString(),
                    'recorded_at' => $r->created_at->toIso8601String(),
                    'amount' => Money::toDecimal($r->amount_minor),
                    'description' => $describe($r),
                    'reference' => $r->reference,
                    'by' => $r->recorder?->name,
                    'is_reversal' => false,
                ]);
                if ($r->isReversed()) {
                    $events->push([
                        'sort_key' => $r->id.'~reversal',
                        'type' => $type.'_reversed',
                        'date' => $r->reversed_at->toDateString(),
                        'recorded_at' => $r->reversed_at->toIso8601String(),
                        'amount' => Money::toDecimal(-$r->amount_minor),
                        'description' => 'Reversed: '.$r->reversal_reason,
                        'reference' => $r->reference,
                        'by' => $r->reverser?->name,
                        'is_reversal' => true,
                    ]);
                }
            }
        };

        $with = ['recorder:id,name', 'reverser:id,name'];

        if ($can('viewPurchase')) {
            $add($car->purchases()->with([...$with, 'dealer:id,name'])->get(), 'purchase', 'purchase_date',
                fn ($p) => 'Purchased from '.$p->dealer->name);
        }
        if ($can('viewExpenses')) {
            $add($car->expenses()->with([...$with, 'expenseType:id,name'])->get(), 'expense', 'expense_date',
                fn ($e) => $e->expenseType->name.($e->description ? ' — '.$e->description : ''));
        }
        if ($can('viewSale')) {
            $add($car->sales()->with([...$with, 'party:id,name'])->get(), 'sale', 'sale_date',
                fn ($s) => 'Sold to '.$s->party->name);
        }
        if ($can('viewPartyPayments')) {
            $add($car->partyPayments()->with($with)->get(), 'party_payment', 'payment_date',
                fn ($p) => 'Payment received ('.str_replace('_', ' ', $p->method->value).')');
        }
        if ($can('viewDealerPayments')) {
            $add($car->dealerPayments()->with($with)->get(), 'dealer_payment', 'payment_date',
                fn ($p) => 'Paid to dealer ('.str_replace('_', ' ', $p->method->value).')');
        }

        // Status changes are part of the car record (visible with car.view).
        AuditLog::with('user:id,name')
            ->where('entity_type', 'car')->where('entity_id', $car->id)->where('action', 'car.status_changed')
            ->get()
            ->each(fn (AuditLog $log) => $events->push([
                'sort_key' => $log->id,
                'type' => 'status_changed',
                'date' => $log->created_at->toDateString(),
                'recorded_at' => $log->created_at->toIso8601String(),
                'amount' => null,
                'description' => 'Status '.($log->old_values['status'] ?? '?').' → '.($log->new_values['status'] ?? '?')
                    .(isset($log->new_values['reason']) ? ' ('.$log->new_values['reason'].')' : ''),
                'reference' => null,
                'by' => $log->user?->name,
                'is_reversal' => false,
            ]));

        return $events
            ->sortBy([['date', 'asc'], ['recorded_at', 'asc'], ['sort_key', 'asc']])
            ->map(fn (array $e) => collect($e)->except('sort_key')->all())
            ->values();
    }
}
