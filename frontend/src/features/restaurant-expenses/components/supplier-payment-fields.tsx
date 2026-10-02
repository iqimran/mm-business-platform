"use client";

import { useWatch, type Control, type FieldErrors, type UseFormRegister, type UseFormSetValue } from "react-hook-form";
import { NativeSelect } from "@/components/common/native-select";
import { FieldError } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { paymentMethodLabels, paymentMethods } from "@/features/restaurant-common/payments";
import { formatAmount } from "@/lib/money";
import { duePreview, type ExpenseValues } from "../schemas";

/**
 * Supplier bills only: how much was paid now. Empty = the full amount; the rest stays due to the supplier.
 */
export function SupplierPaymentFields({
  control,
  register,
  setValue,
  errors,
}: {
  control: Control<ExpenseValues>;
  register: UseFormRegister<ExpenseValues>;
  setValue: UseFormSetValue<ExpenseValues>;
  errors: FieldErrors<ExpenseValues>;
}) {
  const [supplier, amount, paidAmount] = useWatch({ control, name: ["supplier", "amount", "paid_amount"] });
  if (!supplier) return null;

  const due = duePreview(amount, paidAmount);

  return (
    <fieldset className="flex flex-col gap-3 rounded-lg border p-3 sm:col-span-3">
      <legend className="px-1 text-sm font-medium">Paid to {supplier.label} now</legend>
      <div className="grid gap-4 sm:grid-cols-3">
        <div className="flex flex-col gap-2">
          <Label htmlFor="expense-paid">Amount paid</Label>
          <Input id="expense-paid" inputMode="decimal" placeholder="Full amount" aria-invalid={errors.paid_amount ? true : undefined} {...register("paid_amount")} />
          <div className="flex gap-2 text-xs">
            <Button type="button" variant="link" size="sm" className="h-auto p-0 text-xs" onClick={() => setValue("paid_amount", "", { shouldValidate: true })}>
              Full
            </Button>
            <Button type="button" variant="link" size="sm" className="h-auto p-0 text-xs" onClick={() => setValue("paid_amount", "0", { shouldValidate: true })}>
              Pay later (nothing now)
            </Button>
          </div>
          <FieldError id="expense-paid-error" message={errors.paid_amount?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="expense-pay-method">Method</Label>
          <NativeSelect id="expense-pay-method" {...register("payment_method")}>
            {paymentMethods.map((m) => (
              <option key={m} value={m}>
                {paymentMethodLabels[m]}
              </option>
            ))}
          </NativeSelect>
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="expense-pay-reference">Payment reference</Label>
          <Input id="expense-pay-reference" placeholder="Transaction no." aria-invalid={errors.payment_reference ? true : undefined} {...register("payment_reference")} />
          <FieldError id="expense-pay-reference-error" message={errors.payment_reference?.message} />
        </div>
      </div>
      {due !== null ? (
        <p className="text-sm">
          <span className="text-muted-foreground">Due to supplier after saving: </span>
          <span className={`font-semibold tabular-nums ${due !== "0.00" ? "text-destructive" : ""}`}>{formatAmount(due)}</span>
          <span className="text-xs text-muted-foreground"> (preview; the server calculates the final figures)</span>
        </p>
      ) : null}
    </fieldset>
  );
}
