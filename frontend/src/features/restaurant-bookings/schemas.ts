import { z } from "zod";
import { paymentMethods } from "@/features/restaurant-sales/api";
import { toDecimal, toMinor } from "@/features/restaurant-sales/money";
import { today } from "@/features/restaurant-sales/schemas";
import type { BookingInput } from "./api";

const AMOUNT = /^(0|[1-9]\d{0,11})(\.\d{1,2})?$/;
const TIME = /^([01]\d|2[0-3]):[0-5]\d$/;

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
      agreed_amount: z
        .string()
        .trim()
        .min(1, "Agreed amount is required.")
        .regex(AMOUNT, "Enter an amount like 50000 or 50000.50 (no commas).")
        .refine((v) => /[1-9]/.test(v), "Agreed amount must be greater than zero."),
      payment_amount: z.string().trim().refine((v) => v === "" || AMOUNT.test(v), "Enter an amount like 5000 or 5000.50 (no commas)."),
      payment_method: z.enum(paymentMethods),
      payment_reference: z.string().trim().max(100, "Reference must be at most 100 characters."),
      notes: z.string().trim().max(5000, "Notes must be at most 5000 characters."),
    })
    .superRefine((v, ctx) => {
      if (v.customer === null) ctx.addIssue({ code: "custom", path: ["customer"], message: "Select a customer." });
      if (v.booking_date && v.booking_date < today() && v.booking_date !== originalDate) {
        ctx.addIssue({ code: "custom", path: ["booking_date"], message: "The booking date cannot be in the past." });
      }
      if (TIME.test(v.start_time) && TIME.test(v.end_time) && v.end_time <= v.start_time) {
        ctx.addIssue({ code: "custom", path: ["end_time"], message: "The end time must be after the start time." });
      }
      const agreed = toMinor(v.agreed_amount);
      const paying = toMinor(v.payment_amount || "0") ?? 0;
      if (mode === "create" && agreed !== null && paying > agreed) {
        ctx.addIssue({ code: "custom", path: ["payment_amount"], message: `The payment exceeds the agreed amount of ${toDecimal(agreed)}.` });
      }
    });
}

export type BookingValues = z.infer<ReturnType<typeof bookingSchema>>;

export function toBookingInput(v: BookingValues): BookingInput {
  const paying = toMinor(v.payment_amount || "0") ?? 0;

  return {
    hall_id: v.hall_id,
    customer_id: v.customer?.id ?? "",
    booking_date: v.booking_date,
    start_time: v.start_time,
    end_time: v.end_time,
    agreed_amount: v.agreed_amount.trim(),
    notes: v.notes.trim() || null,
    payment: paying > 0 ? { amount: v.payment_amount.trim(), method: v.payment_method, reference: v.payment_reference.trim() || null } : null,
  };
}

/** Edit payload: booking details only (payments are recorded separately). */
export function toBookingChanges(v: BookingValues): Omit<BookingInput, "payment"> {
  const { hall_id, customer_id, booking_date, start_time, end_time, agreed_amount, notes } = toBookingInput(v);
  return { hall_id, customer_id, booking_date, start_time, end_time, agreed_amount, notes };
}

/** API validation errors → booking form fields. */
export function bookingErrorFields(errors: Record<string, string[]>): [string, string][] {
  const direct = ["hall_id", "booking_date", "start_time", "end_time", "agreed_amount", "notes"];

  return Object.entries(errors).flatMap(([key, messages]): [string, string][] => {
    const message = messages[0];
    if (!message) return [];
    if (direct.includes(key)) return [[key, message]];
    if (key === "customer_id") return [["customer", message]];
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
