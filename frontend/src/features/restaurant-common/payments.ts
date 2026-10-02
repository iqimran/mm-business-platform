import { z } from "zod";
import { today } from "./dates";
import { AMOUNT_PATTERN, toDecimal, toMinor } from "./money";

/**
 * Payment vocabulary shared by food sales and hall bookings (same backend rules:
 * due = amount − active payments; payments are reversed, never edited).
 */
export const paymentMethods = ["cash", "bank_transfer", "cheque", "mobile_banking", "other"] as const;
export type PaymentMethod = (typeof paymentMethods)[number];

export const paymentMethodLabels: Record<PaymentMethod, string> = {
  cash: "Cash",
  bank_transfer: "Bank transfer",
  cheque: "Cheque",
  mobile_banking: "Mobile banking",
  other: "Other",
};

/** Derived by the server from the amount and active payments. */
export type PaymentStatus = "unpaid" | "partial" | "paid";

export const paymentStatusLabels: Record<PaymentStatus, string> = {
  unpaid: "Unpaid",
  partial: "Partially paid",
  paid: "Paid",
};

export type PaymentRecord = {
  id: string;
  payment_date: string;
  amount: string;
  method: PaymentMethod;
  reference: string | null;
  notes: string | null;
  recorded_by: { id: string; name: string } | null;
  is_reversed: boolean;
  reversed_at: string | null;
  reversal_reason: string | null;
};

export type PaymentInput = {
  payment_date: string;
  amount: string;
  method: PaymentMethod;
  reference: string | null;
  notes: string | null;
};

/** Required positive amount ("1500" or "1500.50"). */
export const amountText = (label: string) =>
  z
    .string()
    .trim()
    .min(1, `${label} is required.`)
    .regex(AMOUNT_PATTERN, "Enter an amount like 1500 or 1500.50 (no commas).")
    .refine((v) => /[1-9]/.test(v), `${label} must be greater than zero.`);

/** Optional amount: empty means none. */
export const optionalAmountText = () =>
  z
    .string()
    .trim()
    .refine((v) => v === "" || AMOUNT_PATTERN.test(v), "Enter an amount like 1500 or 1500.50 (no commas).");

export const paymentSchema = z.object({
  payment_date: z
    .string()
    .min(1, "Payment date is required.")
    .refine((v) => v <= today(), "Payment date cannot be in the future."),
  amount: amountText("Amount"),
  method: z.enum(paymentMethods),
  reference: z.string().trim().max(100, "Reference must be at most 100 characters."),
  notes: z.string().trim().max(5000, "Notes must be at most 5000 characters."),
});

export type PaymentValues = z.infer<typeof paymentSchema>;

/** Payment against a known remaining due: overpayment is caught before sending (the API re-checks). */
export function paymentSchemaFor(due: string) {
  const dueMinor = toMinor(due) ?? 0;

  return paymentSchema.superRefine((v, ctx) => {
    const amount = toMinor(v.amount);
    if (amount !== null && amount > dueMinor) {
      ctx.addIssue({ code: "custom", path: ["amount"], message: `The amount exceeds the remaining due of ${toDecimal(dueMinor)}.` });
    }
  });
}
