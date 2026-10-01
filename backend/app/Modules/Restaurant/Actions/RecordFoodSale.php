<?php

namespace App\Modules\Restaurant\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Models\FoodSaleItem;
use App\Modules\Restaurant\Models\FoodSalePayment;
use App\Modules\Restaurant\Models\MenuItem;
use App\Modules\Restaurant\Support\SaleFormulas;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Records a food sale with its lines and an optional first payment in ONE transaction:
 * either everything is saved or nothing is.
 *
 * - Unit prices are taken from the menu (never from the client) and copied onto the lines,
 *   so later menu price changes do not alter the sale.
 * - Menu items must be available (item and category active) at the moment of sale.
 * - The first payment cannot exceed the total; walk-in sales (no customer) must be paid in full.
 */
class RecordFoodSale
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{branch_id: string, customer_id?: string|null, sold_at?: string|null, notes?: string|null,
     *               items: array<int, array{menu_item_id: string, quantity: int}>,
     *               payment?: array{amount: string|int, method: string, reference?: string|null}|null}  $data
     */
    public function handle(User $actor, array $data): FoodSale
    {
        return DB::transaction(function () use ($actor, $data) {
            $lines = $this->priceLines($data['items']);

            try {
                $total = SaleFormulas::total(array_column($lines, 'line_total_minor'));
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['items' => 'The sale total is too large.']);
            }

            $payment = $data['payment'] ?? null;
            $paid = $payment ? Money::toMinor($payment['amount']) : 0;
            $customerId = $data['customer_id'] ?? null;

            if ($paid > $total) {
                throw ValidationException::withMessages([
                    'payment.amount' => 'The payment exceeds the sale total of '.Money::toDecimal($total).'.',
                ]);
            }
            if ($customerId === null && $paid !== $total) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Select a customer for a sale that is not fully paid. Walk-in sales must be paid in full.',
                ]);
            }

            $soldAt = isset($data['sold_at']) ? Carbon::parse($data['sold_at']) : now();
            $number = DB::selectOne("SELECT nextval('restaurant_sale_no_seq') AS n")->n;

            $sale = FoodSale::create([
                'sale_no' => 'FS-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT),
                'branch_id' => $data['branch_id'],
                'customer_id' => $customerId,
                'sold_at' => $soldAt,
                'total_minor' => $total,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            foreach ($lines as $line) {
                FoodSaleItem::create($line + ['sale_id' => $sale->id]);
            }

            $this->audit->record('restaurant.sale.created', 'restaurant_sale', $sale->id, $actor->id, $sale->branch_id, newValues: [
                'sale_no' => $sale->sale_no,
                'customer_id' => $customerId,
                'sold_at' => $soldAt->toIso8601String(),
                'items' => array_map(fn (array $line) => [
                    'menu_item_id' => $line['menu_item_id'],
                    'name' => $line['item_name'],
                    'quantity' => $line['quantity'],
                    'unit_price' => Money::toDecimal($line['unit_price_minor']),
                    'line_total' => Money::toDecimal($line['line_total_minor']),
                ], $lines),
                'total' => Money::toDecimal($total),
            ]);

            if ($payment) {
                $record = FoodSalePayment::create([
                    'sale_id' => $sale->id,
                    'branch_id' => $sale->branch_id,
                    'payment_date' => $soldAt->toDateString(),
                    'amount_minor' => $paid,
                    'method' => $payment['method'],
                    'reference' => $payment['reference'] ?? null,
                    'recorded_by' => $actor->id,
                ]);

                $this->audit->record('restaurant.sale_payment.recorded', 'restaurant_sale_payment', $record->id, $actor->id, $sale->branch_id, newValues: [
                    'sale_id' => $sale->id,
                    'payment_date' => $record->payment_date->toDateString(),
                    'amount' => Money::toDecimal($paid),
                    'method' => $record->method->value,
                    'due_after' => Money::toDecimal(SaleFormulas::due($total, $paid)),
                ]);
            }

            return $sale;
        });
    }

    /**
     * Locks the menu items (shared lock: prices cannot change mid-sale) and prices each line.
     *
     * @param  array<int, array{menu_item_id: string, quantity: int}>  $items
     * @return array<int, array{menu_item_id: string, item_name: string, unit_price_minor: int, quantity: int, line_total_minor: int}>
     */
    private function priceLines(array $items): array
    {
        $menu = MenuItem::query()
            ->with('category:id,is_active')
            ->whereIn('id', array_column($items, 'menu_item_id'))
            ->sharedLock()
            ->get()
            ->keyBy('id');

        $lines = [];
        foreach (array_values($items) as $index => $item) {
            $menuItem = $menu->get($item['menu_item_id']);

            if ($menuItem === null || ! $menuItem->is_active || ! $menuItem->category->is_active) {
                throw ValidationException::withMessages(["items.{$index}.menu_item_id" => 'This menu item is not available.']);
            }

            try {
                $lineTotal = SaleFormulas::lineTotal((int) $item['quantity'], $menuItem->price_minor);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => 'The line total is too large.']);
            }

            $lines[] = [
                'menu_item_id' => $menuItem->id,
                'item_name' => $menuItem->name,
                'unit_price_minor' => $menuItem->price_minor,
                'quantity' => (int) $item['quantity'],
                'line_total_minor' => $lineTotal,
            ];
        }

        return $lines;
    }
}
