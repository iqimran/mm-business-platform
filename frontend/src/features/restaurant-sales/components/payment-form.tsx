"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { NativeSelect } from "@/components/common/native-select";
import { FieldError, FormAlert } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { applyApiErrors } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import { paymentMethodLabels, paymentMethods, type FoodSale } from "../api";
import { useRecordPayment } from "../hooks";
import { paymentSchema, today, type PaymentValues } from "../schemas";

/** Payment against the due; the API rejects amounts above the remaining due. */
export function PaymentForm({ sale, onDone }: { sale: FoodSale; onDone: () => void }) {
  const record = useRecordPayment(sale.id);
  const {
    register,
    handleSubmit,
    setError,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<PaymentValues>({
    resolver: zodResolver(paymentSchema),
    defaultValues: { payment_date: today(), amount: "", method: "cash", reference: "", notes: "" },
  });

  const submit = handleSubmit(async (v) => {
    try {
      await record.mutateAsync({ ...v, reference: v.reference || null, notes: v.notes || null });
      onDone();
    } catch (e) {
      applyApiErrors(e, setError, ["payment_date", "amount", "method", "reference", "notes"]);
    }
  });

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4 rounded-lg border bg-muted/30 p-4">
      <FormAlert message={errors.root?.message} />
      <div className="grid gap-4 sm:grid-cols-3">
        <div className="flex flex-col gap-2">
          <Label htmlFor="pay-date">Payment date</Label>
          <Input id="pay-date" type="date" max={today()} aria-invalid={errors.payment_date ? true : undefined} {...register("payment_date")} />
          <FieldError id="pay-date-error" message={errors.payment_date?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="pay-amount">Amount</Label>
          <Input id="pay-amount" inputMode="decimal" aria-invalid={errors.amount ? true : undefined} {...register("amount")} />
          <button
            type="button"
            className="w-fit text-xs text-muted-foreground underline-offset-2 hover:underline"
            onClick={() => setValue("amount", sale.due, { shouldValidate: true })}
          >
            Due: {formatAmount(sale.due)} (use full amount)
          </button>
          <FieldError id="pay-amount-error" message={errors.amount?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="pay-method">Method</Label>
          <NativeSelect id="pay-method" {...register("method")}>
            {paymentMethods.map((m) => (
              <option key={m} value={m}>
                {paymentMethodLabels[m]}
              </option>
            ))}
          </NativeSelect>
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="pay-reference">Reference</Label>
          <Input id="pay-reference" placeholder="Transaction no." aria-invalid={errors.reference ? true : undefined} {...register("reference")} />
          <FieldError id="pay-reference-error" message={errors.reference?.message} />
        </div>
        <div className="flex flex-col gap-2 sm:col-span-2">
          <Label htmlFor="pay-notes">Notes</Label>
          <Input id="pay-notes" aria-invalid={errors.notes ? true : undefined} {...register("notes")} />
          <FieldError id="pay-notes-error" message={errors.notes?.message} />
        </div>
      </div>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onDone}>
          Cancel
        </Button>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? "Recording…" : "Record payment"}
        </Button>
      </div>
    </form>
  );
}
