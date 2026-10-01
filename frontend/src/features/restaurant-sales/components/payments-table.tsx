"use client";

import { Badge } from "@/components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { formatAmount } from "@/lib/money";
import { paymentMethodLabels, type SalePayment } from "../api";
import { ReverseButton } from "./reverse-button";

/** Payments of a restaurant obligation (food sale or hall booking); reversed ones stay visible. */
export function PaymentsTable({
  payments,
  canReverse,
  onReverse,
}: {
  payments: SalePayment[];
  canReverse: boolean;
  onReverse: (paymentId: string, reason: string) => Promise<unknown>;
}) {
  if (payments.length === 0) return <p className="text-sm text-muted-foreground">No payments recorded.</p>;

  return (
    <div className="rounded-lg border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Date</TableHead>
            <TableHead>Method</TableHead>
            <TableHead className="hidden md:table-cell">Reference</TableHead>
            <TableHead className="hidden lg:table-cell">Recorded by</TableHead>
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
              <TableCell className="hidden lg:table-cell">{p.recorded_by?.name ?? "—"}</TableCell>
              <TableCell className={`text-right tabular-nums ${p.is_reversed ? "line-through" : ""}`}>{formatAmount(p.amount)}</TableCell>
              <TableCell className="whitespace-normal text-right">
                {p.is_reversed ? (
                  <Badge variant="outline">Reversed</Badge>
                ) : canReverse ? (
                  <ReverseButton label="payment" onReverse={(reason) => onReverse(p.id, reason)} />
                ) : null}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
