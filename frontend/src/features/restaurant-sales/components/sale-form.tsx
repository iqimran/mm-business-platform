"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { Controller, useFieldArray, useForm, useWatch, type Path } from "react-hook-form";
import { NativeSelect } from "@/components/common/native-select";
import { FieldError, FormAlert } from "@/components/common/page-header";
import { RecordPicker } from "@/components/common/record-picker";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { useSession } from "@/features/auth/hooks";
import { ApiError } from "@/lib/api-client";
import { errorMessage } from "@/lib/form-errors";
import { paymentMethodLabels, paymentMethods, searchCustomers } from "../api";
import { useCreateSale } from "../hooks";
import { saleTotalMinor, toDecimal, toMinor } from "../money";
import { nowLocal, saleErrorFields, saleSchema, toSaleInput, type SaleValues } from "../schemas";
import { SaleFigures } from "./sale-figures";
import { SaleLines } from "./sale-lines";

export function SaleForm() {
  const router = useRouter();
  const { data: session } = useSession();
  const branches = session?.branches ?? [];
  const create = useCreateSale();

  const {
    register,
    control,
    handleSubmit,
    setError,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<SaleValues>({
    resolver: zodResolver(saleSchema),
    defaultValues: {
      branch_id: branches.length === 1 ? branches[0].id : "",
      customer: null,
      sold_at: nowLocal(),
      items: [],
      payment_amount: "",
      payment_method: "cash",
      payment_reference: "",
      notes: "",
    },
  });
  const lines = useFieldArray({ control, name: "items" });

  const items = useWatch({ control, name: "items" });
  const paymentAmount = useWatch({ control, name: "payment_amount" });
  const total = saleTotalMinor(items);
  const paying = Math.min(toMinor(paymentAmount || "0") ?? 0, total);

  const submit = handleSubmit(async (v) => {
    try {
      const sale = await create.mutateAsync(toSaleInput(v));
      router.push(`/restaurant/sales/${sale.id}`);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        for (const [field, message] of saleErrorFields(e.errors)) setError(field as Path<SaleValues>, { message });
        return;
      }
      setError("root", { message: errorMessage(e) });
    }
  });

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-6">
      <FormAlert message={errors.root?.message} />

      <div className="grid gap-4 sm:grid-cols-3">
        <div className="flex flex-col gap-2">
          <Label htmlFor="sale-branch">Branch</Label>
          <NativeSelect id="sale-branch" aria-invalid={errors.branch_id ? true : undefined} {...register("branch_id")}>
            <option value="">Select a branch…</option>
            {branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.code} — {b.name}
              </option>
            ))}
          </NativeSelect>
          <FieldError id="sale-branch-error" message={errors.branch_id?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="sale-customer">Customer</Label>
          <Controller
            control={control}
            name="customer"
            render={({ field }) => (
              <RecordPicker
                id="sale-customer"
                value={field.value}
                onChange={field.onChange}
                search={searchCustomers}
                queryKey="restaurant-customers-active"
                placeholder="Walk-in (search to select)"
                invalid={Boolean(errors.customer)}
                describedBy="sale-customer-error"
              />
            )}
          />
          <FieldError id="sale-customer-error" message={errors.customer?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="sale-time">Sale time</Label>
          <Input id="sale-time" type="datetime-local" max={nowLocal()} aria-invalid={errors.sold_at ? true : undefined} {...register("sold_at")} />
          <FieldError id="sale-time-error" message={errors.sold_at?.message} />
        </div>
      </div>

      <SaleLines lines={lines} values={items} register={register} errors={errors} />

      <fieldset className="flex flex-col gap-4 rounded-lg border p-4">
        <legend className="px-1 text-sm font-medium">Payment received now</legend>
        <div className="grid gap-4 sm:grid-cols-3">
          <div className="flex flex-col gap-2">
            <Label htmlFor="sale-pay-amount">Amount</Label>
            <Input id="sale-pay-amount" inputMode="decimal" placeholder="0.00 (unpaid)" aria-invalid={errors.payment_amount ? true : undefined} {...register("payment_amount")} />
            <button
              type="button"
              className="w-fit text-xs text-muted-foreground underline-offset-2 hover:underline disabled:opacity-50"
              disabled={total === 0}
              onClick={() => setValue("payment_amount", toDecimal(total), { shouldValidate: true })}
            >
              Full payment ({toDecimal(total)})
            </button>
            <FieldError id="sale-pay-amount-error" message={errors.payment_amount?.message} />
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="sale-pay-method">Method</Label>
            <NativeSelect id="sale-pay-method" {...register("payment_method")}>
              {paymentMethods.map((m) => (
                <option key={m} value={m}>
                  {paymentMethodLabels[m]}
                </option>
              ))}
            </NativeSelect>
            <FieldError id="sale-pay-method-error" message={errors.payment_method?.message} />
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="sale-pay-reference">Reference</Label>
            <Input id="sale-pay-reference" placeholder="Transaction no." aria-invalid={errors.payment_reference ? true : undefined} {...register("payment_reference")} />
            <FieldError id="sale-pay-reference-error" message={errors.payment_reference?.message} />
          </div>
        </div>
        <SaleFigures total={toDecimal(total)} paid={toDecimal(paying)} due={toDecimal(total - paying)} labels={["Total", "Paying now", "Due after sale"]} />
        <p className="text-xs text-muted-foreground">Preview only. The server applies menu prices at the moment of sale and calculates the final figures.</p>
      </fieldset>

      <div className="flex flex-col gap-2">
        <Label htmlFor="sale-notes">Notes</Label>
        <Textarea id="sale-notes" rows={2} aria-invalid={errors.notes ? true : undefined} {...register("notes")} />
        <FieldError id="sale-notes-error" message={errors.notes?.message} />
      </div>

      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={() => router.push("/restaurant/sales")}>
          Cancel
        </Button>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? "Saving…" : "Record sale"}
        </Button>
      </div>
    </form>
  );
}
