"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useNotify } from "@/components/common/notifications";
import { Controller, useForm } from "react-hook-form";
import { NativeSelect } from "@/components/common/native-select";
import { FieldError, FormAlert } from "@/components/common/page-header";
import { RecordPicker } from "@/components/common/record-picker";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { usePermissions, useSession } from "@/features/auth/hooks";
import { searchSuppliers } from "@/features/restaurant-common/lookups";
import { applyApiErrors } from "@/lib/form-errors";
import { useCreateExpense, useExpenseCategories } from "../hooks";
import { expenseSchema, toExpenseInput, type ExpenseValues } from "../schemas";
import { SupplierPaymentFields } from "./supplier-payment-fields";
import { today } from "@/features/restaurant-common/dates";

export function ExpenseForm({ onDone }: { onDone: () => void }) {
  const { data: session } = useSession();
  const branches = session?.branches ?? [];
  const categories = useExpenseCategories(true);
  const create = useCreateExpense();
  const { can } = usePermissions();
  const canViewSuppliers = can("restaurant.supplier.view");
  const notify = useNotify();

  const {
    register,
    control,
    handleSubmit,
    setError,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<ExpenseValues>({
    resolver: zodResolver(expenseSchema),
    defaultValues: {
      branch_id: branches.length === 1 ? branches[0].id : "",
      category_id: "",
      supplier: null,
      expense_date: today(),
      amount: "",
      description: "",
      reference: "",
      paid_amount: "",
      payment_method: "cash",
      payment_reference: "",
    },
  });

  const submit = handleSubmit(async (v) => {
    try {
      await create.mutateAsync(toExpenseInput(v));
      notify("Expense recorded.");
      onDone();
    } catch (e) {
      applyApiErrors(e, setError, ["branch_id", "category_id", "expense_date", "amount", "description", "reference", "paid_amount", "payment_method", "payment_reference"], { supplier_id: "supplier" });
    }
  });

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4 rounded-lg border bg-muted/30 p-4">
      <FormAlert message={errors.root?.message} />
      <div className="grid gap-4 sm:grid-cols-3">
        <div className="flex flex-col gap-2">
          <Label htmlFor="expense-date">Date</Label>
          <Input id="expense-date" type="date" max={today()} aria-invalid={errors.expense_date ? true : undefined} {...register("expense_date")} />
          <FieldError id="expense-date-error" message={errors.expense_date?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="expense-category">Category</Label>
          <NativeSelect id="expense-category" disabled={categories.isPending} aria-invalid={errors.category_id ? true : undefined} {...register("category_id")}>
            <option value="">{categories.isPending ? "Loading…" : categories.isError ? "Could not load categories" : "Select a category…"}</option>
            {(categories.data ?? []).map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </NativeSelect>
          {categories.data && categories.data.length === 0 ? (
            <p className="text-xs text-muted-foreground">No active categories. Add them under Restaurant → Expense categories.</p>
          ) : null}
          <FieldError id="expense-category-error" message={errors.category_id?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="expense-amount">Amount</Label>
          <Input id="expense-amount" inputMode="decimal" placeholder="0.00" aria-invalid={errors.amount ? true : undefined} {...register("amount")} />
          <FieldError id="expense-amount-error" message={errors.amount?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="expense-branch">Branch</Label>
          <NativeSelect id="expense-branch" aria-invalid={errors.branch_id ? true : undefined} {...register("branch_id")}>
            <option value="">Select a branch…</option>
            {branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.code} — {b.name}
              </option>
            ))}
          </NativeSelect>
          <FieldError id="expense-branch-error" message={errors.branch_id?.message} />
        </div>
        {canViewSuppliers ? (
          <div className="flex flex-col gap-2">
            <Label htmlFor="expense-supplier">Supplier (optional)</Label>
            <Controller
              control={control}
              name="supplier"
              render={({ field }) => (
                <RecordPicker
                  id="expense-supplier"
                  value={field.value}
                  onChange={field.onChange}
                  search={searchSuppliers}
                  queryKey="restaurant-suppliers-active"
                  placeholder="Search suppliers…"
                  invalid={Boolean(errors.supplier)}
                  describedBy="expense-supplier-error"
                />
              )}
            />
            <FieldError id="expense-supplier-error" message={errors.supplier?.message} />
          </div>
        ) : null}
        <div className="flex flex-col gap-2">
          <Label htmlFor="expense-reference">Reference</Label>
          <Input id="expense-reference" placeholder="Bill / invoice no." aria-invalid={errors.reference ? true : undefined} {...register("reference")} />
          <FieldError id="expense-reference-error" message={errors.reference?.message} />
        </div>
        <div className="flex flex-col gap-2 sm:col-span-3">
          <Label htmlFor="expense-description">Description</Label>
          <Input id="expense-description" aria-invalid={errors.description ? true : undefined} {...register("description")} />
          <FieldError id="expense-description-error" message={errors.description?.message} />
        </div>
        <SupplierPaymentFields control={control} register={register} setValue={setValue} errors={errors} />
      </div>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onDone}>
          Cancel
        </Button>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? "Saving…" : "Record expense"}
        </Button>
      </div>
    </form>
  );
}
