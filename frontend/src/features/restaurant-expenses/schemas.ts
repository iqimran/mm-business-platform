import { z } from "zod";
import { AMOUNT_PATTERN, toDecimal, toMinor } from "@/features/restaurant-common/money";
import { amountText, paymentMethods } from "@/features/restaurant-common/payments";
import type { ExpenseInput } from "./api";
import { today } from "@/features/restaurant-common/dates";

export const expenseSchema = z
  .object({
  branch_id: z.string().min(1, "Select a branch."),
  category_id: z.string().min(1, "Select a category."),
  supplier: z.object({ id: z.string(), label: z.string() }).nullable(),
  expense_date: z
    .string()
    .min(1, "Expense date is required.")
    .refine((v) => v <= today(), "The expense date cannot be in the future."),
  amount: amountText("Amount"),
  description: z.string().trim().max(255, "Description must be at most 255 characters."),
  reference: z.string().trim().max(100, "Reference must be at most 100 characters."),
  /** Paid now: empty = the full amount. */
  paid_amount: z.string().trim(),
  payment_method: z.enum(paymentMethods),
  payment_reference: z.string().trim().max(100, "Reference must be at most 100 characters."),
  })
  .superRefine((v, ctx) => {
    if (v.paid_amount === "") return;
    if (!AMOUNT_PATTERN.test(v.paid_amount)) {
      ctx.addIssue({ code: "custom", path: ["paid_amount"], message: "Enter an amount like 1500 or 1500.50 (no commas)." });
      return;
    }
    const amount = toMinor(v.amount);
    const paid = toMinor(v.paid_amount) ?? 0;
    if (amount !== null && paid > amount) ctx.addIssue({ code: "custom", path: ["paid_amount"], message: "The amount paid cannot exceed the expense amount." });
    if (v.supplier === null && amount !== null && paid !== amount) {
      ctx.addIssue({ code: "custom", path: ["paid_amount"], message: "Select a supplier for an expense that is not fully paid." });
    }
  });

/** Supplier due preview for the form (the server calculates the real figures). */
export function duePreview(amount: string, paidAmount: string): string | null {
  const total = toMinor(amount);
  if (total === null) return null;
  const paid = paidAmount.trim() === "" ? total : toMinor(paidAmount);
  return paid === null ? null : toDecimal(Math.max(total - paid, 0));
}

export type ExpenseValues = z.infer<typeof expenseSchema>;

export function toExpenseInput(v: ExpenseValues): ExpenseInput {
  return {
    branch_id: v.branch_id,
    category_id: v.category_id,
    supplier_id: v.supplier?.id ?? null,
    expense_date: v.expense_date,
    amount: v.amount.trim(),
    description: v.description.trim() || null,
    reference: v.reference.trim() || null,
    paid_amount: v.supplier && v.paid_amount.trim() !== "" ? v.paid_amount.trim() : null,
    payment_method: v.payment_method,
    payment_reference: v.supplier ? v.payment_reference.trim() || null : null,
  };
}

/** Inclusive number of days in a range; null when invalid. The server allows at most 366. */
export function rangeDays(from: string, to: string): number | null {
  const start = Date.parse(`${from}T00:00:00Z`);
  const end = Date.parse(`${to}T00:00:00Z`);
  if (Number.isNaN(start) || Number.isNaN(end) || end < start) return null;
  return Math.round((end - start) / 86_400_000) + 1;
}
