"use client";

import { Printer } from "lucide-react";
import { useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import { paymentMethodLabels, type PaymentRecord } from "../payments";
import { ReverseButton } from "./reverse-button";

/** Payments of a restaurant obligation (food sale or hall booking); reversed ones stay visible. */
export function PaymentsTable({
  payments,
  canReverse,
  onReverse,
  onPrint,
}: {
  payments: PaymentRecord[];
  canReverse: boolean;
  onReverse: (paymentId: string, reason: string) => Promise<unknown>;
  /** Opens the server-generated money receipt (PDF) for a payment. */
  onPrint?: (paymentId: string) => Promise<void>;
}) {
  const [printing, setPrinting] = useState<string | null>(null);
  const [printError, setPrintError] = useState<string>();

  const print = async (paymentId: string) => {
    setPrinting(paymentId);
    setPrintError(undefined);
    try {
      await onPrint?.(paymentId);
    } catch (e) {
      setPrintError(errorMessage(e));
    } finally {
      setPrinting(null);
    }
  };

  if (payments.length === 0) return <p className="text-sm text-muted-foreground">No payments recorded.</p>;

  return (
    <div className="flex flex-col gap-2">
      {printError ? <p className="text-sm text-destructive">{printError}</p> : null}
      <div className="rounded-lg border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Date</TableHead>
              <TableHead>Method</TableHead>
              <TableHead className="hidden md:table-cell">Reference</TableHead>
              <TableHead className="hidden lg:table-cell">Recorded by</TableHead>
              <TableHead className="text-right">Amount</TableHead>
              <TableHead className="w-48">
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
                  <div className="flex flex-wrap items-center justify-end gap-1">
                    {onPrint ? (
                      <Button variant="ghost" size="sm" aria-label={`Print receipt for payment of ${p.payment_date}`} disabled={printing !== null} onClick={() => print(p.id)}>
                        <Printer aria-hidden />
                        {printing === p.id ? "…" : "Receipt"}
                      </Button>
                    ) : null}
                    {p.is_reversed ? (
                      <Badge variant="outline">Reversed</Badge>
                    ) : canReverse ? (
                      <ReverseButton label="payment" onReverse={(reason) => onReverse(p.id, reason)} />
                    ) : null}
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
