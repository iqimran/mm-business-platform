"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Printer } from "lucide-react";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { NativeSelect } from "@/components/common/native-select";
import { FieldError, FormAlert } from "@/components/common/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { applyApiErrors, errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import {
  paymentMethodLabels,
  paymentMethods,
  type Payment,
  type PaymentInput,
} from "../api";
import { paymentSchema, today, type PaymentValues } from "../schemas";
import { ReverseButton } from "./reverse-button";

/** Shared by party (customer) and dealer payments; the API rejects amounts above what is outstanding. */
export function PaymentForm({
  idPrefix,
  outstanding,
  outstandingLabel,
  onSubmit,
  onCancel,
}: {
  idPrefix: string;
  outstanding: string;
  outstandingLabel: string;
  onSubmit: (input: PaymentInput) => Promise<unknown>;
  onCancel: () => void;
}) {
  const {
    register,
    handleSubmit,
    setError,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<PaymentValues>({
    resolver: zodResolver(paymentSchema),
    defaultValues: {
      payment_date: today(),
      amount: "",
      method: "cash",
      reference: "",
      notes: "",
    },
  });

  const submit = handleSubmit(async (v) => {
    try {
      await onSubmit({
        ...v,
        reference: v.reference || null,
        notes: v.notes || null,
      });
      onCancel();
    } catch (e) {
      applyApiErrors(e, setError, [
        "payment_date",
        "amount",
        "method",
        "reference",
        "notes",
      ]);
    }
  });

  return (
    <form
      onSubmit={submit}
      noValidate
      className="flex flex-col gap-4 rounded-lg border bg-muted/30 p-4"
    >
      <FormAlert message={errors.root?.message} />
      <div className="grid gap-4 sm:grid-cols-3">
        <div className="flex flex-col gap-2">
          <Label htmlFor={`${idPrefix}-date`}>Payment date</Label>
          <Input
            id={`${idPrefix}-date`}
            type="date"
            max={today()}
            aria-invalid={errors.payment_date ? true : undefined}
            {...register("payment_date")}
          />
          <FieldError
            id={`${idPrefix}-date-error`}
            message={errors.payment_date?.message}
          />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor={`${idPrefix}-amount`}>Amount</Label>
          <Input
            id={`${idPrefix}-amount`}
            inputMode="decimal"
            aria-invalid={errors.amount ? true : undefined}
            {...register("amount")}
          />
          <button
            type="button"
            className="w-fit text-xs text-muted-foreground underline-offset-2 hover:underline"
            onClick={() =>
              setValue("amount", outstanding, { shouldValidate: true })
            }
          >
            {outstandingLabel}: {formatAmount(outstanding)} (use full amount)
          </button>
          <FieldError
            id={`${idPrefix}-amount-error`}
            message={errors.amount?.message}
          />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor={`${idPrefix}-method`}>Method</Label>
          <NativeSelect id={`${idPrefix}-method`} {...register("method")}>
            {paymentMethods.map((m) => (
              <option key={m} value={m}>
                {paymentMethodLabels[m]}
              </option>
            ))}
          </NativeSelect>
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor={`${idPrefix}-reference`}>Reference</Label>
          <Input
            id={`${idPrefix}-reference`}
            placeholder="Cheque / transaction no."
            aria-invalid={errors.reference ? true : undefined}
            {...register("reference")}
          />
          <FieldError
            id={`${idPrefix}-reference-error`}
            message={errors.reference?.message}
          />
        </div>
        <div className="flex flex-col gap-2 sm:col-span-2">
          <Label htmlFor={`${idPrefix}-notes`}>Notes</Label>
          <Input
            id={`${idPrefix}-notes`}
            aria-invalid={errors.notes ? true : undefined}
            {...register("notes")}
          />
          <FieldError
            id={`${idPrefix}-notes-error`}
            message={errors.notes?.message}
          />
        </div>
      </div>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? "Recording…" : "Record payment"}
        </Button>
      </div>
    </form>
  );
}

export function PaymentsTable({
  payments,
  canReverse,
  onReverse,
  onPrint,
  printLabel = "Print",
}: {
  payments: Payment[];
  canReverse: boolean;
  onReverse: (id: string, reason: string) => Promise<unknown>;
  /** Opens the server-generated slip (receipt/voucher) for a payment. */
  onPrint?: (id: string) => Promise<void>;
  printLabel?: string;
}) {
  const [reversing, setReversing] = useState<string | null>(null);
  const [printing, setPrinting] = useState<string | null>(null);
  const [printError, setPrintError] = useState<string>();

  const print = async (id: string) => {
    setPrinting(id);
    setPrintError(undefined);
    try {
      await onPrint?.(id);
    } catch (e) {
      setPrintError(errorMessage(e));
    } finally {
      setPrinting(null);
    }
  };

  if (payments.length === 0)
    return (
      <p className="text-sm text-muted-foreground">No payments recorded.</p>
    );

  return (
    <div className="flex flex-col gap-2">
      {printError ? (
        <p className="text-sm text-destructive">{printError}</p>
      ) : null}
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
              <TableRow
                key={p.id}
                className={p.is_reversed ? "text-muted-foreground" : undefined}
              >
                <TableCell className="tabular-nums">{p.payment_date}</TableCell>
                <TableCell>{paymentMethodLabels[p.method]}</TableCell>
                <TableCell className="hidden whitespace-normal md:table-cell">
                  {p.reference ?? "—"}
                  {p.is_reversed ? (
                    <div className="text-xs">Reversed: {p.reversal_reason}</div>
                  ) : null}
                </TableCell>
                <TableCell
                  className={`text-right tabular-nums ${p.is_reversed ? "line-through" : ""}`}
                >
                  {formatAmount(p.amount)}
                </TableCell>
                <TableCell className="whitespace-normal">
                  <div className="flex flex-wrap items-center justify-end gap-1">
                    {onPrint ? (
                      <Button
                        variant="ghost"
                        size="sm"
                        aria-label={`${printLabel} for payment of ${p.payment_date}`}
                        disabled={printing !== null}
                        onClick={() => print(p.id)}
                      >
                        <Printer aria-hidden />
                        {printing === p.id ? "…" : printLabel}
                      </Button>
                    ) : null}
                    {p.is_reversed ? (
                      <Badge variant="outline">Reversed</Badge>
                    ) : canReverse ? (
                      reversing === p.id ? (
                        <ReverseButton
                          label="payment"
                          onReverse={async (reason) => {
                            await onReverse(p.id, reason);
                            setReversing(null);
                          }}
                        />
                      ) : (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setReversing(p.id)}
                        >
                          Reverse
                        </Button>
                      )
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

/** Three-figure summary used for Party Due and Dealer Payable (kept as separate components on purpose). */
export function Position({ items }: { items: [string, string, boolean?][] }) {
  return (
    <dl className="grid grid-cols-3 gap-3 rounded-lg border bg-muted/30 p-3 text-sm">
      {items.map(([label, value, strong]) => (
        <div key={label}>
          <dt className="text-muted-foreground">{label}</dt>
          <dd
            className={strong ? "font-semibold tabular-nums" : "tabular-nums"}
          >
            {formatAmount(value)}
          </dd>
        </div>
      ))}
    </dl>
  );
}
