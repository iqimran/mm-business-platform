"use client";

import { Plus } from "lucide-react";
import { useNotify } from "@/components/common/notifications";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { usePermissions } from "@/features/auth/hooks";
import { formatAmount } from "@/lib/money";
import { printSalePaymentReceipt, type FoodSale } from "../api";
import { useRecordPayment, useReversePayment, useReverseSale } from "../hooks";
import { PaymentForm } from "@/features/restaurant-common/components/payment-form";
import { PaymentStatusBadge } from "@/features/restaurant-common/components/payment-status-badge";
import { PaymentsTable } from "@/features/restaurant-common/components/payments-table";
import { ReverseButton } from "@/features/restaurant-common/components/reverse-button";
import { AmountSummary } from "@/features/restaurant-common/components/amount-summary";

function Items({ sale }: { sale: FoodSale }) {
  return (
    <div className="rounded-lg border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Item</TableHead>
            <TableHead className="text-right">Unit price</TableHead>
            <TableHead className="text-right">Quantity</TableHead>
            <TableHead className="text-right">Line total</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {(sale.items ?? []).map((item) => (
            <TableRow key={item.id}>
              <TableCell className="font-medium whitespace-normal">{item.item_name}</TableCell>
              <TableCell className="text-right tabular-nums">{formatAmount(item.unit_price)}</TableCell>
              <TableCell className="text-right tabular-nums">{item.quantity}</TableCell>
              <TableCell className="text-right tabular-nums">{formatAmount(item.line_total)}</TableCell>
            </TableRow>
          ))}
          <TableRow>
            <TableCell colSpan={3} className="text-right font-medium">
              Total
            </TableCell>
            <TableCell className="text-right font-semibold tabular-nums">{formatAmount(sale.total)}</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>
  );
}

export function SaleDetail({ sale }: { sale: FoodSale }) {
  const { can } = usePermissions();
  const [paying, setPaying] = useState(false);
  const reverseSale = useReverseSale(sale.id);
  const recordPayment = useRecordPayment(sale.id);
  const reversePayment = useReversePayment(sale.id);
  const notify = useNotify();
  const activePayments = (sale.payments ?? []).some((p) => !p.is_reversed);

  const canPay = can("restaurant.sale_payment.create") && !sale.is_reversed && sale.payment_status !== "paid";
  const canReverseSale = can("restaurant.sale.reverse") && !sale.is_reversed;

  const details: [string, string][] = [
    ["Branch", sale.branch ? `${sale.branch.code} — ${sale.branch.name}` : "—"],
    ["Customer", sale.customer ? `${sale.customer.name}${sale.customer.phone ? ` (${sale.customer.phone})` : ""}` : "Walk-in"],
    ["Sale time", new Date(sale.sold_at).toLocaleString()],
    ["Recorded by", sale.recorded_by?.name ?? "—"],
  ];

  return (
    <div className="flex flex-col gap-6">
      {sale.is_reversed ? (
        <p className="rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm">
          This sale was reversed{sale.reversed_by ? ` by ${sale.reversed_by.name}` : ""}: {sale.reversal_reason}
        </p>
      ) : null}

      <Card>
        <CardHeader className="flex flex-row items-center justify-between gap-2">
          <CardTitle>Summary</CardTitle>
          <PaymentStatusBadge status={sale.payment_status} voided={sale.is_reversed} />
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <dl className="grid gap-3 text-sm sm:grid-cols-4">
            {details.map(([label, value]) => (
              <div key={label}>
                <dt className="text-muted-foreground">{label}</dt>
                <dd>{value}</dd>
              </div>
            ))}
          </dl>
          <AmountSummary total={sale.total} paid={sale.paid} due={sale.due} />
          {sale.notes ? <p className="text-sm whitespace-pre-line text-muted-foreground">{sale.notes}</p> : null}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Items</CardTitle>
        </CardHeader>
        <CardContent>
          <Items sale={sale} />
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between gap-2">
          <CardTitle>Payments</CardTitle>
          {canPay && !paying ? (
            <Button size="sm" onClick={() => setPaying(true)}>
              <Plus aria-hidden />
              Record payment
            </Button>
          ) : null}
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          {paying ? <PaymentForm due={sale.due} onSubmit={(input) => recordPayment.mutateAsync(input)} onDone={() => setPaying(false)} /> : null}
          <PaymentsTable
            payments={sale.payments ?? []}
            canReverse={can("restaurant.sale_payment.reverse") && !sale.is_reversed}
            onReverse={(paymentId, reason) => reversePayment.mutateAsync({ paymentId, reason }).then(() => notify("Payment reversed."))}
            onPrint={(paymentId) => printSalePaymentReceipt(sale.id, paymentId)}
          />
        </CardContent>
      </Card>

      {canReverseSale ? (
        <Card>
          <CardHeader>
            <CardTitle>Reverse sale</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-2 text-sm">
            {activePayments ? (
              <p className="text-muted-foreground">Reverse this sale&apos;s payments first; a sale with payments cannot be reversed.</p>
            ) : (
              <>
                <p className="text-muted-foreground">Cancels the sale. It stays in history and no longer counts in totals.</p>
                <div>
                  <ReverseButton label="sale" onReverse={(reason) => reverseSale.mutateAsync(reason).then(() => notify(`Sale ${sale.sale_no} reversed.`))} />
                </div>
              </>
            )}
          </CardContent>
        </Card>
      ) : null}
    </div>
  );
}
