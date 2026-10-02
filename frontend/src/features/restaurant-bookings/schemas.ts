import { z } from "zod";
import { today } from "@/features/restaurant-common/dates";
import { AMOUNT_PATTERN, toDecimal, toMinor } from "@/features/restaurant-common/money";
import { optionalAmountText, paymentMethods } from "@/features/restaurant-common/payments";
import type { BookingInput } from "./api";

const TIME = /^([01]\d|2[0-3]):[0-5]\d$/;
export const MAX_GUESTS = 100000;

/** Whole number of guests 1..100000, otherwise null. */
export function toGuests(value: string): number | null {
  const v = value.trim();
  if (!/^\d{1,6}$/.test(v)) return null;
  const n = Number(v);
  return n >= 1 && n <= MAX_GUESTS ? n : null;
}

/**
 * Preview of the booking figures in minor units (the server recalculates and stores the real ones):
 * package total = guests × price per head; booking total = hall charge + package total.
 */
export function bookingPreview(v: { hall_charge: string; has_package: boolean; package_guests: string; package_price: string }) {
  const hall = toMinor(v.hall_charge) ?? 0;
  const guests = toGuests(v.package_guests);
  const price = toMinor(v.package_price);
  const pkg = v.has_package && guests !== null && price !== null ? guests * price : 0;
  return { hall, pkg, total: hall + pkg };
}

const pickedItem = z.object({ id: z.string(), label: z.string() });

/**
 * Booking form. `originalDate` (edit mode) may stay in the past; a new or moved booking may not.
 */
export function bookingSchema(mode: "create" | "edit", originalDate?: string) {
  return z
    .object({
      hall_id: z.string().min(1, "Select a hall."),
      customer: z.object({ id: z.string(), label: z.string() }).nullable(),
      booking_date: z.string().min(1, "Booking date is required."),
      start_time: z.string().regex(TIME, "Enter a start time."),
      end_time: z.string().regex(TIME, "Enter an end time."),
      hall_charge: z
        .string()
        .trim()
        .min(1, "Hall charge is required (enter 0 if there is none).")
        .regex(AMOUNT_PATTERN, "Enter an amount like 50000 or 50000.50 (no commas)."),
      has_package: z.boolean(),
      package_name: z.string().trim().max(150, "Package name must be at most 150 characters."),
      package_guests: z.string().trim(),
      package_price: z.string().trim(),
      package_items: z.array(pickedItem).max(100, "A package can contain at most 100 items."),
      package_notes: z.string().trim().max(2000, "Package notes must be at most 2000 characters."),
      payment_amount: optionalAmountText(),
      payment_method: z.enum(paymentMethods),
      payment_reference: z.string().trim().max(100, "Reference must be at most 100 characters."),
      notes: z.string().trim().max(5000, "Notes must be at most 5000 characters."),
    })
    .superRefine((v, ctx) => {
      const issue = (path: string, message: string) => ctx.addIssue({ code: "custom", path: [path], message });

      if (v.customer === null) issue("customer", "Select a customer.");
      if (v.booking_date && v.booking_date < today() && v.booking_date !== originalDate) issue("booking_date", "The booking date cannot be in the past.");
      if (TIME.test(v.start_time) && TIME.test(v.end_time) && v.end_time <= v.start_time) issue("end_time", "The end time must be after the start time.");

      if (v.has_package) {
        if (v.package_name === "") issue("package_name", "Give the food package a name.");
        if (toGuests(v.package_guests) === null) issue("package_guests", `Enter a whole number of guests from 1 to ${MAX_GUESTS}.`);
        if (!AMOUNT_PATTERN.test(v.package_price) || !/[1-9]/.test(v.package_price)) issue("package_price", "Enter a price per head greater than zero, like 800 or 800.50.");
        if (v.package_items.length === 0) issue("package_items", "Select at least one event menu item.");
      }

      const { total } = bookingPreview(v);
      if (AMOUNT_PATTERN.test(v.hall_charge) && total <= 0) issue("hall_charge", "Enter a hall charge or add a food package.");

      const paying = toMinor(v.payment_amount || "0") ?? 0;
      if (mode === "create" && paying > total && total > 0) issue("payment_amount", `The payment exceeds the booking total of ${toDecimal(total)}.`);
    });
}

export type BookingValues = z.infer<ReturnType<typeof bookingSchema>>;

function toPackageInput(v: BookingValues): BookingInput["food_package"] {
  if (!v.has_package) return null;
  return {
    name: v.package_name.trim(),
    guest_count: toGuests(v.package_guests) ?? 0,
    price_per_head: v.package_price.trim(),
    event_menu_item_ids: v.package_items.map((item) => item.id),
    notes: v.package_notes.trim() || null,
  };
}

export function toBookingInput(v: BookingValues): BookingInput {
  const paying = toMinor(v.payment_amount || "0") ?? 0;

  return {
    hall_id: v.hall_id,
    customer_id: v.customer?.id ?? "",
    booking_date: v.booking_date,
    start_time: v.start_time,
    end_time: v.end_time,
    hall_charge: v.hall_charge.trim(),
    food_package: toPackageInput(v),
    notes: v.notes.trim() || null,
    payment: paying > 0 ? { amount: v.payment_amount.trim(), method: v.payment_method, reference: v.payment_reference.trim() || null } : null,
  };
}

/** Edit payload: booking details and package (null removes it); payments are recorded separately. */
export function toBookingChanges(v: BookingValues): Omit<BookingInput, "payment"> {
  const { hall_id, customer_id, booking_date, start_time, end_time, hall_charge, food_package, notes } = toBookingInput(v);
  return { hall_id, customer_id, booking_date, start_time, end_time, hall_charge, food_package, notes };
}

/** API validation errors → booking form fields. */
export function bookingErrorFields(errors: Record<string, string[]>): [string, string][] {
  const direct = ["hall_id", "booking_date", "start_time", "end_time", "hall_charge", "notes"];
  const pkg: Record<string, string> = {
    "food_package.name": "package_name",
    "food_package.guest_count": "package_guests",
    "food_package.price_per_head": "package_price",
    "food_package.notes": "package_notes",
  };

  return Object.entries(errors).flatMap(([key, messages]): [string, string][] => {
    const message = messages[0];
    if (!message) return [];
    if (direct.includes(key)) return [[key, message]];
    if (key === "agreed_amount") return [["hall_charge", message]];
    if (key === "customer_id") return [["customer", message]];
    if (pkg[key]) return [[pkg[key], message]];
    if (key === "food_package" || key.startsWith("food_package.event_menu_item_ids")) return [["package_items", message]];
    if (key === "payment.method") return [["payment_method", message]];
    if (key === "payment.reference") return [["payment_reference", message]];
    if (key.startsWith("payment")) return [["payment_amount", message]];
    return [["root", message]];
  });
}

/** Booked slots that overlap [start, end) — a preview; the server decides. */
export function overlapping<T extends { start_time: string; end_time: string }>(slots: T[], start: string, end: string): T[] {
  if (!TIME.test(start) || !TIME.test(end) || end <= start) return [];
  return slots.filter((s) => s.start_time < end && s.end_time > start);
}
