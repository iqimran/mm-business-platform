"use client";

import { Plus } from "lucide-react";
import { useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { usePermissions } from "@/features/auth/hooks";
import { formatAmount } from "@/lib/money";
import { paymentMethodLabels, type FoodSale } from "../api";
import { useReversePayment, useReverseSale } from "../hooks";
import { PaymentForm } from "./payment-form";
import { PaymentStatusBadge } from "./payment-status-badge";
import { ReverseButton } from "./reverse-button";
import { SaleFigures } from "./sale-figures";

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

function Payments({ sale, canReverse }: { sale: FoodSale; canReverse: boolean }) {
  const reverse = useReversePayment(sale.id);
  const payments = sale.payments ?? [];

  if (payments.length === 0) return <p className="text-sm text-muted-foreground">No payments recorded.</p>;

  return (
    <div className="rounded-lg border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Date</TableHead>
            <TableHead>Method</TableHead>
            <TableHead className="hidden md:table-cell">Reference</TableHead>
            <TableHead className="text-right">Amount</TableHead>
            <TableHead className="w-40">
              <span className="sr-only">Actions</span>
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {payments.map((p) => (
            <TableRow key={p.id} className={p.is_reversed ? "text-muted-foreground" : undefined}>
              <TableCell className="tabular-nums">{p.payment_date}</TableCell>
              <TableCell>{paymentMethodLabels[p.method]}</TableCell>
              <TableCell className="hidden whitespace-normal md:table-cell">
                {p.reference ?? "—"}
                {p.is_reversed ? <div className="text-xs">Reversed: {p.reversal_reason}</div> : null}
              </TableCell>
              <TableCell className={`text-right tabular-nums ${p.is_reversed ? "line-through" : ""}`}>{formatAmount(p.amount)}</TableCell>
              <TableCell className="whitespace-normal text-right">
                {p.is_reversed ? (
                  <Badge variant="outline">Reversed</Badge>
                ) : canReverse ? (
                  <ReverseButton label="payment" onReverse={(reason) => reverse.mutateAsync({ paymentId: p.id, reason })} />
                ) : null}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

export function SaleDetail({ sale }: { sale: FoodSale }) {
  const { can } = usePermissions();
  const [paying, setPaying] = useState(false);
  const reverseSale = useReverseSale(sale.id);
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
          <PaymentStatusBadge sale={sale} />
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
          <SaleFigures total={sale.total} paid={sale.paid} due={sale.due} />
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
          {paying ? <PaymentForm sale={sale} onDone={() => setPaying(false)} /> : null}
          <Payments sale={sale} canReverse={can("restaurant.sale_payment.reverse") && !sale.is_reversed} />
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
                  <ReverseButton label="sale" onReverse={(reason) => reverseSale.mutateAsync(reason)} />
                </div>
              </>
            )}
          </CardContent>
        </Card>
      ) : null}
    </div>
  );
}
