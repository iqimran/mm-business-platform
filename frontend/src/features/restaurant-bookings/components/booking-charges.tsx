import { formatAmount } from "@/lib/money";
import type { HallBooking } from "../api";

/** Hall charge + event food package = booking total, as stored by the server. */
export function BookingCharges({ booking }: { booking: HallBooking }) {
  const pkg = booking.food_package;

  return (
    <div className="flex flex-col gap-3 rounded-lg border p-3 text-sm">
      <dl className="grid gap-1">
        <div className="flex justify-between gap-4">
          <dt className="text-muted-foreground">Hall charge</dt>
          <dd className="tabular-nums">{formatAmount(booking.hall_charge)}</dd>
        </div>
        <div className="flex justify-between gap-4">
          <dt className="text-muted-foreground">
            Food package
            {pkg ? (
              <span className="ml-1">
                ({pkg.guest_count} guests × {formatAmount(pkg.price_per_head)})
              </span>
            ) : null}
          </dt>
          <dd className="tabular-nums">{pkg ? formatAmount(pkg.total) : "—"}</dd>
        </div>
        <div className="flex justify-between gap-4 border-t pt-1 font-semibold">
          <dt>Booking total</dt>
          <dd className="tabular-nums">{formatAmount(booking.booking_total)}</dd>
        </div>
      </dl>
      {pkg ? (
        <div className="flex flex-col gap-1">
          <div className="font-medium">{pkg.name}</div>
          <ul className="flex flex-wrap gap-1.5" aria-label="Package food items">
            {(pkg.items ?? []).map((item) => (
              <li key={item.event_menu_item_id} className="rounded-md bg-muted px-2 py-0.5">
                {item.item_name}
              </li>
            ))}
          </ul>
          {pkg.notes ? <p className="whitespace-pre-line text-muted-foreground">{pkg.notes}</p> : null}
        </div>
      ) : (
        <p className="text-muted-foreground">No event food package.</p>
      )}
    </div>
  );
}
