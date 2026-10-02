import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";
import type { HallBooking } from "./api";
import { BookingCharges } from "./components/booking-charges";

const booking = (overrides: Partial<HallBooking> = {}): HallBooking =>
  ({
    id: "b1", booking_no: "HB-000001", booking_date: "2026-10-10", start_time: "18:00", end_time: "23:00", status: "confirmed",
    hall_charge: "100000.00", booking_total: "340000.00", agreed_amount: "340000.00", paid: "100000.00", due: "240000.00",
    payment_status: "partial", notes: null, cancelled_at: null, cancellation_reason: null,
    food_package: {
      id: "p1", name: "Wedding Dinner Package", guest_count: 300, price_per_head: "800.00", total: "240000.00", notes: "Serve at 8 pm",
      items: [{ event_menu_item_id: "m1", item_name: "Polao" }, { event_menu_item_id: "m2", item_name: "Roast" }],
    },
    ...overrides,
  }) as HallBooking;

describe("booking charges (server figures)", () => {
  it("shows hall charge + food package = booking total with the package items", () => {
    const html = renderToStaticMarkup(<BookingCharges booking={booking()} />);
    expect(html).toContain("100,000.00");
    // Plain text (React inserts markers between adjacent text nodes).
    const text = html.replace(/<!-- -->/g, "").replace(/<[^>]+>/g, " ").replace(/\s+/g, " ");
    expect(text).toContain("(300 guests × 800.00)");
    expect(html).toContain("240,000.00");
    expect(html).toContain("340,000.00");
    expect(html).toContain("Wedding Dinner Package");
    expect(html).toContain("Polao");
    expect(html).toContain("Roast");
    expect(html).toContain("Serve at 8 pm");
  });

  it("handles a hall-only booking", () => {
    const html = renderToStaticMarkup(<BookingCharges booking={booking({ food_package: null, booking_total: "100000.00" })} />);
    expect(html).toContain("No event food package.");
    expect(html).not.toContain("guests ×");
  });
});
