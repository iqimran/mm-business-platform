import { z } from "zod";
import { amountText } from "@/features/restaurant-common/payments";
import type { ExpenseInput } from "./api";
import { today } from "@/features/restaurant-common/dates";

export const expenseSchema = z.object({
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
});

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
  };
}

/** Inclusive number of days in a range; null when invalid. The server allows at most 366. */
export function rangeDays(from: string, to: string): number | null {
  const start = Date.parse(`${from}T00:00:00Z`);
  const end = Date.parse(`${to}T00:00:00Z`);
  if (Number.isNaN(start) || Number.isNaN(end) || end < start) return null;
  return Math.round((end - start) / 86_400_000) + 1;
}
