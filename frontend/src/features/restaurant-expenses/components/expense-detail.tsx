"use client";

import { Plus } from "lucide-react";
import { useState } from "react";
import { useNotify } from "@/components/common/notifications";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { usePermissions } from "@/features/auth/hooks";
import { AmountSummary } from "@/features/restaurant-common/components/amount-summary";
import { PaymentForm } from "@/features/restaurant-common/components/payment-form";
import { PaymentStatusBadge } from "@/features/restaurant-common/components/payment-status-badge";
import { PaymentsTable } from "@/features/restaurant-common/components/payments-table";
import { ReverseButton } from "@/features/restaurant-common/components/reverse-button";
import { formatAmount } from "@/lib/money";
import { printExpenseVoucher, type RestaurantExpense } from "../api";
import { useRecordExpensePayment, useReverseExpense, useReverseExpensePayment } from "../hooks";

/** One expense / supplier bill: details, supplier payments (bill-wise) with A4 vouchers, reversal. */
export function ExpenseDetail({ expense }: { expense: RestaurantExpense }) {
  const { can } = usePermissions();
  const notify = useNotify();
  const [paying, setPaying] = useState(false);
  const recordPayment = useRecordExpensePayment(expense.id);
  const reversePayment = useReverseExpensePayment(expense.id);
  const reverseExpense = useReverseExpense();

  const activePayments = (expense.payments ?? []).some((p) => !p.is_reversed);
  const canPay = Boolean(expense.supplier) && !expense.is_reversed && expense.due !== "0.00" && can("restaurant.supplier_payment.create");
  const canReverseExpense = !expense.is_reversed && can("restaurant.expense.reverse");

  const details: [string, string][] = [
    ["Date", expense.expense_date],
    ["Category", expense.category?.name ?? "—"],
    ["Supplier", expense.supplier?.name ?? "No supplier (paid in full)"],
    ["Branch", expense.branch ? `${expense.branch.code} — ${expense.branch.name}` : "—"],
    ["Bill / reference", expense.reference ?? "—"],
    ["Recorded by", expense.recorded_by?.name ?? "—"],
  ];

  return (
    <div className="flex flex-col gap-6">
      {expense.is_reversed ? (
        <p className="rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm">
          This expense was reversed{expense.reversed_by ? ` by ${expense.reversed_by.name}` : ""}: {expense.reversal_reason}
        </p>
      ) : null}

      <Card>
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
          <CardTitle>Expense</CardTitle>
          {expense.supplier && !expense.is_reversed ? <PaymentStatusBadge status={expense.payment_status} /> : null}
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <dl className="grid gap-3 text-sm sm:grid-cols-3">
            {details.map(([label, value]) => (
              <div key={label}>
                <dt className="text-muted-foreground">{label}</dt>
                <dd>{value}</dd>
              </div>
            ))}
          </dl>
          {expense.description ? <p className="text-sm text-muted-foreground">{expense.description}</p> : null}
          <AmountSummary total={expense.amount} paid={expense.paid} due={expense.due} labels={["Bill amount", "Paid", "Due to supplier"]} />
        </CardContent>
      </Card>

      {expense.supplier ? (
        <Card>
          <CardHeader className="flex flex-row items-center justify-between gap-2">
            <CardTitle>Supplier payments</CardTitle>
            {canPay && !paying ? (
              <Button size="sm" onClick={() => setPaying(true)}>
                <Plus aria-hidden />
                Pay supplier
              </Button>
            ) : null}
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {expense.due === "0.00" && !expense.is_reversed ? (
              <p className="text-sm text-muted-foreground">Fully paid. Reverse a payment first if it needs correcting.</p>
            ) : null}
            {paying ? (
              <PaymentForm due={expense.due} onSubmit={(input) => recordPayment.mutateAsync(input)} onDone={() => setPaying(false)} />
            ) : null}
            <PaymentsTable
              payments={expense.payments ?? []}
              canReverse={can("restaurant.supplier_payment.reverse")}
              onReverse={(paymentId, reason) => reversePayment.mutateAsync({ paymentId, reason }).then(() => notify("Supplier payment reversed."))}
              onPrint={(paymentId) => printExpenseVoucher(expense.id, paymentId)}
            />
          </CardContent>
        </Card>
      ) : null}

      {canReverseExpense ? (
        <Card>
          <CardHeader>
            <CardTitle>Reverse expense</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-2 text-sm">
            {activePayments ? (
              <p className="text-muted-foreground">Reverse this bill&apos;s supplier payments first; an expense with payments cannot be reversed.</p>
            ) : (
              <>
                <p className="text-muted-foreground">
                  Corrects a wrong entry ({formatAmount(expense.amount)}). It stays in history and no longer counts in totals.
                </p>
                <div>
                  <ReverseButton label="expense" onReverse={(reason) => reverseExpense.mutateAsync({ id: expense.id, reason }).then(() => notify("Expense reversed."))} />
                </div>
              </>
            )}
          </CardContent>
        </Card>
      ) : null}
    </div>
  );
}
