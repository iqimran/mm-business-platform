"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { FileSpreadsheet, FileText, Plus } from "lucide-react";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { NativeSelect } from "@/components/common/native-select";
import { FieldError, FormAlert } from "@/components/common/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { usePermissions } from "@/features/auth/hooks";
import { exportReport } from "@/features/car-reports/api";
import { expenseTypeResource } from "@/features/car-master/config";
import { useActiveOptions } from "@/features/car-master/hooks";
import { applyApiErrors, errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import type { CarCosts } from "../api";
import { useExpenses, useRecordExpense, useReverseExpense } from "../hooks";
import { expenseSchema, today, type ExpenseValues } from "../schemas";
import { ReverseButton } from "./reverse-button";

function ExpenseForm({ carId, onDone }: { carId: string; onDone: () => void }) {
  const record = useRecordExpense(carId);
  const types = useActiveOptions(expenseTypeResource);
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ExpenseValues>({
    resolver: zodResolver(expenseSchema),
    defaultValues: { expense_type_id: "", expense_date: today(), amount: "", description: "", reference: "" },
  });

  const submit = handleSubmit(async (v) => {
    try {
      await record.mutateAsync({ ...v, description: v.description || null, reference: v.reference || null });
      onDone();
    } catch (e) {
      applyApiErrors(e, setError, ["expense_type_id", "expense_date", "amount", "description", "reference"]);
    }
  });

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4 rounded-lg border bg-muted/30 p-4">
      <FormAlert message={errors.root?.message} />
      <div className="grid gap-4 sm:grid-cols-3">
        <div className="flex flex-col gap-2">
          <Label htmlFor="expense-type">Expense type</Label>
          <NativeSelect id="expense-type" aria-invalid={errors.expense_type_id ? true : undefined} {...register("expense_type_id")}>
            <option value="">Select…</option>
            {(types.data ?? []).map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </NativeSelect>
          <FieldError id="expense-type-error" message={errors.expense_type_id?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="expense-date">Date</Label>
          <Input id="expense-date" type="date" max={today()} aria-invalid={errors.expense_date ? true : undefined} {...register("expense_date")} />
          <FieldError id="expense-date-error" message={errors.expense_date?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="expense-amount">Amount</Label>
          <Input id="expense-amount" inputMode="decimal" placeholder="25000.00" aria-invalid={errors.amount ? true : undefined} {...register("amount")} />
          <FieldError id="expense-amount-error" message={errors.amount?.message} />
        </div>
        <div className="flex flex-col gap-2 sm:col-span-2">
          <Label htmlFor="expense-description">Description</Label>
          <Input id="expense-description" aria-invalid={errors.description ? true : undefined} {...register("description")} />
          <FieldError id="expense-description-error" message={errors.description?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="expense-reference">Reference</Label>
          <Input id="expense-reference" aria-invalid={errors.reference ? true : undefined} {...register("reference")} />
          <FieldError id="expense-reference-error" message={errors.reference?.message} />
        </div>
      </div>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onDone}>
          Cancel
        </Button>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? "Recording…" : "Record expense"}
        </Button>
      </div>
    </form>
  );
}

export function CostSummary({ costs }: { costs: CarCosts }) {
  const items: [string, string][] = [
    ["Purchase cost", costs.purchase_cost],
    ["Expenses", costs.expenses_total],
    ["Total investment", costs.total_investment],
  ];

  return (
    <dl className="grid grid-cols-3 gap-3 rounded-lg border bg-muted/30 p-3 text-sm">
      {items.map(([label, value], i) => (
        <div key={label}>
          <dt className="text-muted-foreground">{label}</dt>
          <dd className={i === 2 ? "font-semibold tabular-nums" : "tabular-nums"}>{formatAmount(value)}</dd>
        </div>
      ))}
    </dl>
  );
}

export function ExpensesSection({ carId, carCompleted }: { carId: string; carCompleted: boolean }) {
  const { can } = usePermissions();
  const canView = can("car.expense.view");
  const expenses = useExpenses(carId, canView);
  const reverse = useReverseExpense(carId);
  const [adding, setAdding] = useState(false);
  const [reversing, setReversing] = useState<string | null>(null);
  const [exporting, setExporting] = useState<"xlsx" | "pdf" | null>(null);
  const [exportError, setExportError] = useState<string>();

  if (!canView) return null;

  const canAdd = can("car.expense.create") && !carCompleted;
  const canExport = can("car.report.view") && can("car.view");

  // This car's full expense report (all entries + summary by type), generated by the server.
  const download = async (format: "xlsx" | "pdf") => {
    setExporting(format);
    setExportError(undefined);
    try {
      await exportReport("expenses", format, { car_id: carId, sort: "expense_date", direction: "asc" });
    } catch (e) {
      setExportError(errorMessage(e));
    } finally {
      setExporting(null);
    }
  };
  const canReverse = can("car.expense.reverse") && !carCompleted;

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle>Expenses</CardTitle>
          <CardDescription>Costs added to this car. Reversed entries are kept but not counted.</CardDescription>
        </div>
        <div className="flex flex-wrap justify-end gap-2">
          {canExport && expenses.data && expenses.data.items.length > 0 ? (
            <>
              <Button variant="ghost" size="sm" disabled={exporting !== null} onClick={() => download("xlsx")}>
                <FileSpreadsheet aria-hidden />
                {exporting === "xlsx" ? "Exporting…" : "Excel"}
              </Button>
              <Button variant="ghost" size="sm" disabled={exporting !== null} onClick={() => download("pdf")}>
                <FileText aria-hidden />
                {exporting === "pdf" ? "Exporting…" : "PDF"}
              </Button>
            </>
          ) : null}
          {canAdd && !adding ? (
            <Button variant="outline" size="sm" onClick={() => setAdding(true)}>
              <Plus aria-hidden />
              Add expense
            </Button>
          ) : null}
        </div>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {exportError ? <p className="text-sm text-destructive">{exportError}</p> : null}
        {expenses.data ? <CostSummary costs={expenses.data.costs} /> : null}
        {adding ? <ExpenseForm carId={carId} onDone={() => setAdding(false)} /> : null}
        {expenses.isPending ? <p className="text-sm text-muted-foreground">Loading…</p> : null}
        {expenses.isError ? <p className="text-sm text-destructive">{errorMessage(expenses.error)}</p> : null}

        {expenses.data && expenses.data.items.length === 0 ? <p className="text-sm text-muted-foreground">No expenses recorded.</p> : null}
        {expenses.data && expenses.data.items.length > 0 ? (
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Date</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead className="hidden md:table-cell">Description</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                  <TableHead className="w-28">
                    <span className="sr-only">Actions</span>
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {expenses.data.items.map((e) => (
                  <TableRow key={e.id} className={e.is_reversed ? "text-muted-foreground" : undefined}>
                    <TableCell className="tabular-nums">{e.expense_date}</TableCell>
                    <TableCell>{e.expense_type?.name}</TableCell>
                    <TableCell className="hidden whitespace-normal md:table-cell">
                      {e.description ?? "—"}
                      {e.is_reversed ? <div className="text-xs">Reversed: {e.reversal_reason}</div> : null}
                    </TableCell>
                    <TableCell className={`text-right tabular-nums ${e.is_reversed ? "line-through" : ""}`}>{formatAmount(e.amount)}</TableCell>
                    <TableCell className="whitespace-normal">
                      {e.is_reversed ? (
                        <Badge variant="outline">Reversed</Badge>
                      ) : canReverse ? (
                        reversing === e.id ? (
                          <ReverseButton
                            label="expense"
                            onReverse={async (reason) => {
                              await reverse.mutateAsync({ id: e.id, reason });
                              setReversing(null);
                            }}
                          />
                        ) : (
                          <Button variant="ghost" size="sm" onClick={() => setReversing(e.id)}>
                            Reverse
                          </Button>
                        )
                      ) : null}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}
