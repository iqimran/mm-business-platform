import { z } from "zod";

/** Same format the API accepts: positive, at most 2 decimals, no separators or exponents. */
const AMOUNT_PATTERN = /^(0|[1-9]\d{0,11})(\.\d{1,2})?$/;

/** Local calendar date as YYYY-MM-DD (not UTC, which can be a day behind in UTC+ time zones). */
const today = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
};

export const amountField = z
  .string()
  .trim()
  .min(1, "Amount is required.")
  .regex(AMOUNT_PATTERN, "Enter an amount like 1500 or 1500.50 (no commas).")
  .refine((v) => /[1-9]/.test(v), "Amount must be greater than zero.");

const dateField = (label: string) =>
  z
    .string()
    .min(1, `${label} is required.`)
    .refine((v) => v <= today(), `${label} cannot be in the future.`);

export const purchaseSchema = z.object({
  dealer_id: z.string().min(1, "Select a dealer."),
  purchase_date: dateField("Purchase date"),
  amount: amountField,
  reference: z.string().trim().max(100, "Reference must be at most 100 characters."),
  notes: z.string().trim().max(5000, "Notes must be at most 5000 characters."),
});

export const expenseSchema = z.object({
  expense_type_id: z.string().min(1, "Select an expense type."),
  expense_date: dateField("Expense date"),
  amount: amountField,
  description: z.string().trim().max(255, "Description must be at most 255 characters."),
  reference: z.string().trim().max(100, "Reference must be at most 100 characters."),
});

export const saleSchema = z.object({
  party_id: z.string().min(1, "Select a party (customer)."),
  sale_date: dateField("Sale date"),
  amount: amountField,
  reference: z.string().trim().max(100, "Reference must be at most 100 characters."),
  notes: z.string().trim().max(5000, "Notes must be at most 5000 characters."),
});

export const paymentSchema = z.object({
  payment_date: dateField("Payment date"),
  amount: amountField,
  method: z.enum(["cash", "bank_transfer", "cheque", "mobile_banking", "other"]),
  reference: z.string().trim().max(100, "Reference must be at most 100 characters."),
  notes: z.string().trim().max(5000, "Notes must be at most 5000 characters."),
});

export type SaleValues = z.infer<typeof saleSchema>;
export type PaymentValues = z.infer<typeof paymentSchema>;
export type PurchaseValues = z.infer<typeof purchaseSchema>;
export type ExpenseValues = z.infer<typeof expenseSchema>;

export { today };
