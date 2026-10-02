<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\EventMenuItem;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Models\HallBookingFoodPackage;
use App\Modules\Restaurant\Support\FoodPackageFormulas;
use App\Modules\Shared\Support\Money;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Sets or removes the event food package of a booking. Must run inside the booking's transaction
 * (with the booking row locked); the caller recalculates and saves the booking total.
 *
 * - Total is always calculated here (guest count × price per head); client totals are ignored.
 * - Items come from the event menu (no per-item prices). Newly added items must be active; items already
 *   in the package stay even if later deactivated or renamed (their name was copied when they were added).
 */
class FoodPackages
{
    /**
     * @param  array{name: string, guest_count: int, price_per_head: string|int, event_menu_item_ids: list<string>, notes?: ?string}  $input
     * @return array<string, mixed>|null snapshot before the change (null if there was no package)
     */
    public function set(User $actor, HallBooking $booking, array $input): ?array
    {
        $existing = $booking->foodPackage()->with('items')->first();
        $before = $existing ? self::snapshot($existing) : null;

        try {
            $price = Money::toMinor($input['price_per_head']);
            $total = FoodPackageFormulas::packageTotal((int) $input['guest_count'], $price);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['food_package.guest_count' => 'The food package total is too large.']);
        }

        $ids = array_values(array_unique($input['event_menu_item_ids']));
        $kept = $existing ? $existing->items->keyBy('event_menu_item_id') : collect();
        $menu = EventMenuItem::query()->whereIn('id', $ids)->sharedLock()->get()->keyBy('id');

        foreach ($ids as $index => $id) {
            $item = $menu->get($id);
            $available = $item !== null && $item->is_active;
            if (! $kept->has($id) && ! $available) {
                throw ValidationException::withMessages(["food_package.event_menu_item_ids.{$index}" => 'This event menu item is not available.']);
            }
        }

        $package = $existing ?? new HallBookingFoodPackage(['booking_id' => $booking->id, 'branch_id' => $booking->branch_id, 'created_by' => $actor->id]);
        $package->fill([
            'name' => $input['name'],
            'guest_count' => (int) $input['guest_count'],
            'price_per_head_minor' => $price,
            'total_minor' => $total,
            'notes' => $input['notes'] ?? null,
        ])->save();

        // Replace the item list: keep the copied names of items that stay, copy current names for new ones.
        $package->items()->whereNotIn('event_menu_item_id', $ids)->delete();
        foreach ($ids as $order => $id) {
            $current = $kept->get($id);
            if ($current) {
                $current->update(['sort_order' => $order]);
            } else {
                $package->items()->create(['event_menu_item_id' => $id, 'item_name' => $menu->get($id)->name, 'sort_order' => $order]);
            }
        }

        $booking->setRelation('foodPackage', $package->load('items'));

        return $before;
    }

    /**
     * @return array<string, mixed>|null snapshot of the removed package
     */
    public function remove(HallBooking $booking): ?array
    {
        $existing = $booking->foodPackage()->with('items')->first();
        if ($existing === null) {
            return null;
        }

        $before = self::snapshot($existing);
        $existing->delete(); // items are removed with it
        $booking->setRelation('foodPackage', null);

        return $before;
    }

    /**
     * Audit/receipt view of a package.
     *
     * @return array{name: string, guest_count: int, price_per_head: string, total: string, notes: ?string, items: list<array{event_menu_item_id: string, name: string}>}
     */
    public static function snapshot(HallBookingFoodPackage $package): array
    {
        return [
            'name' => $package->name,
            'guest_count' => $package->guest_count,
            'price_per_head' => Money::toDecimal($package->price_per_head_minor),
            'total' => Money::toDecimal($package->total_minor),
            'notes' => $package->notes,
            'items' => $package->items->map(fn ($item) => ['event_menu_item_id' => $item->event_menu_item_id, 'name' => $item->item_name])->values()->all(),
        ];
    }
}
