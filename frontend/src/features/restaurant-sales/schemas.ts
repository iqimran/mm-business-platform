import { z } from "zod";
import { nowLocal } from "@/features/restaurant-common/dates";
import { toDecimal, toMinor } from "@/features/restaurant-common/money";
import { optionalAmountText, paymentMethods } from "@/features/restaurant-common/payments";
import { saleTotalMinor, toQuantity } from "./money";

export const saleLineSchema = z.object({
  menu_item_id: z.string().min(1),
  name: z.string(),
  unit_price: z.string(),
  quantity: z.string().refine((v) => toQuantity(v) !== null, "Enter a whole number from 1 to 9999."),
});

export const saleSchema = z
  .object({
    branch_id: z.string().min(1, "Select a branch."),
    customer: z.object({ id: z.string(), label: z.string() }).nullable(),
    sold_at: z
      .string()
      .min(1, "Sale time is required.")
      .refine((v) => v <= nowLocal(), "The sale time cannot be in the future."),
    items: z.array(saleLineSchema).min(1, "Add at least one menu item."),
    payment_amount: optionalAmountText(),
    payment_method: z.enum(paymentMethods),
    payment_reference: z.string().trim().max(100, "Reference must be at most 100 characters."),
    notes: z.string().trim().max(5000, "Notes must be at most 5000 characters."),
  })
  .superRefine((v, ctx) => {
    const total = saleTotalMinor(v.items);
    const paid = toMinor(v.payment_amount || "0") ?? 0;

    if (paid > total) {
      ctx.addIssue({ code: "custom", path: ["payment_amount"], message: `The payment exceeds the sale total of ${toDecimal(total)}.` });
    }
    if (v.customer === null && paid !== total) {
      ctx.addIssue({ code: "custom", path: ["customer"], message: "Select a customer, or take full payment for a walk-in sale." });
    }
  });

export type SaleValues = z.infer<typeof saleSchema>;

export function toSaleInput(v: SaleValues) {
  const paid = toMinor(v.payment_amount || "0") ?? 0;

  return {
    branch_id: v.branch_id,
    customer_id: v.customer?.id ?? null,
    sold_at: v.sold_at.replace("T", " "),
    notes: v.notes.trim() || null,
    items: v.items.map((line) => ({ menu_item_id: line.menu_item_id, quantity: toQuantity(line.quantity) ?? 0 })),
    payment: paid > 0 ? { amount: v.payment_amount, method: v.payment_method, reference: v.payment_reference.trim() || null } : null,
  };
}

/**
 * API validation errors → form fields. Line errors (items.N.*) go to that line's quantity;
 * payment.* to the payment fields; customer_id to the customer picker.
 */
export function saleErrorFields(errors: Record<string, string[]>): [string, string][] {
  return Object.entries(errors).flatMap(([key, messages]): [string, string][] => {
    const message = messages[0];
    if (!message) return [];
    const line = /^items\.(\d+)\./.exec(key);
    if (line) return [[`items.${line[1]}.quantity`, message]];
    if (key === "items") return [["items", message]];
    if (key === "payment.method") return [["payment_method", message]];
    if (key === "payment.reference") return [["payment_reference", message]];
    if (key.startsWith("payment")) return [["payment_amount", message]];
    if (key === "customer_id") return [["customer", message]];
    if (["branch_id", "sold_at", "notes"].includes(key)) return [[key, message]];
    return [["root", message]];
  });
}
