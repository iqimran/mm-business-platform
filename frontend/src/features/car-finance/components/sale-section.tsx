"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Plus } from "lucide-react";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { FieldError, FormAlert } from "@/components/common/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { usePermissions } from "@/features/auth/hooks";
import { partyResource } from "@/features/car-master/config";
import { MasterRecordField } from "@/features/car-master/components/master-record-field";
import type { CarStatus } from "@/features/cars/api";
import { applyApiErrors, errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import { printPartyReceipt } from "../api";
import { useRecordPartyPayment, useRecordSale, useReversePartyPayment, useReverseSale, useSale } from "../hooks";
import { saleSchema, today, type SaleValues } from "../schemas";
import { PaymentForm, PaymentsTable, Position } from "./payments";
import { ReverseButton } from "./reverse-button";

const SELLABLE: CarStatus[] = ["IN_STOCK", "PREPARATION", "READY_FOR_SALE"];

function SaleForm({ carId, onDone }: { carId: string; onDone: () => void }) {
  const record = useRecordSale(carId);
  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<SaleValues>({
    resolver: zodResolver(saleSchema),
    defaultValues: { party_id: "", sale_date: today(), amount: "", reference: "", notes: "" },
  });

  const submit = handleSubmit(async (v) => {
    try {
      await record.mutateAsync({ ...v, reference: v.reference || null, notes: v.notes || null });
      onDone();
    } catch (e) {
      applyApiErrors(e, setError, ["party_id", "sale_date", "amount", "reference", "notes"]);
    }
  });

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      <FormAlert message={errors.root?.message} />
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-2">
          <Label htmlFor="sale-party">Party (customer)</Label>
          <MasterRecordField
            control={control}
            name="party_id"
            resource={partyResource}
            id="sale-party"
            placeholder="Search customer by name, phone or NID…"
            invalid={!!errors.party_id}
          />
          <FieldError id="sale-party-error" message={errors.party_id?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="sale-date">Sale date</Label>
          <Input id="sale-date" type="date" max={today()} aria-invalid={errors.sale_date ? true : undefined} {...register("sale_date")} />
          <FieldError id="sale-date-error" message={errors.sale_date?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="sale-amount">Sale amount</Label>
          <Input id="sale-amount" inputMode="decimal" placeholder="850000.00" aria-invalid={errors.amount ? true : undefined} {...register("amount")} />
          <FieldError id="sale-amount-error" message={errors.amount?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="sale-reference">Reference / invoice no.</Label>
          <Input id="sale-reference" aria-invalid={errors.reference ? true : undefined} {...register("reference")} />
          <FieldError id="sale-reference-error" message={errors.reference?.message} />
        </div>
        <div className="flex flex-col gap-2 sm:col-span-2">
          <Label htmlFor="sale-notes">Notes</Label>
          <Textarea id="sale-notes" rows={2} aria-invalid={errors.notes ? true : undefined} {...register("notes")} />
          <FieldError id="sale-notes-error" message={errors.notes?.message} />
        </div>
      </div>
      <p className="text-xs text-muted-foreground">Recording the sale marks the car as Sold. To correct it later, reverse it (after reversing its payments) and record again.</p>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onDone}>
          Cancel
        </Button>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? "Recording…" : "Record sale"}
        </Button>
      </div>
    </form>
  );
}

export function SaleSection({ carId, status }: { carId: string; status: CarStatus }) {
  const { can } = usePermissions();
  const canView = can("car.sale.view");
  const sale = useSale(carId, canView);
  const reverseSale = useReverseSale(carId);
  const recordPayment = useRecordPartyPayment(carId);
  const reversePayment = useReversePartyPayment(carId);
  const [selling, setSelling] = useState(false);
  const [paying, setPaying] = useState(false);

  if (!canView) return null;

  const data = sale.data;
  const active = data?.active;
  const completed = status === "COMPLETED";
  const reversedSales = data?.history.filter((s) => s.is_reversed) ?? [];

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle>Sale & customer payments</CardTitle>
          <CardDescription>Party due = sale amount − payments received.</CardDescription>
        </div>
        {active && can("car.payment.create") && !completed && data?.party && !data.party.is_settled && !paying ? (
          <Button variant="outline" size="sm" onClick={() => setPaying(true)}>
            <Plus aria-hidden />
            Receive payment
          </Button>
        ) : null}
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {sale.isPending ? <p className="text-sm text-muted-foreground">Loading…</p> : null}
        {sale.isError ? <p className="text-sm text-destructive">{errorMessage(sale.error)}</p> : null}

        {data && !active ? (
          selling ? (
            <SaleForm carId={carId} onDone={() => setSelling(false)} />
          ) : (
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="text-sm text-muted-foreground">
                {SELLABLE.includes(status) ? "Not sold yet." : "Move the car to In stock, Preparation or Ready for sale before selling."}
              </p>
              {can("car.sale.create") && SELLABLE.includes(status) ? <Button onClick={() => setSelling(true)}>Record sale</Button> : null}
            </div>
          )
        ) : null}

        {active && data ? (
          <>
            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
              <div>
                <dt className="text-muted-foreground">Sold to</dt>
                <dd className="font-medium">
                  {active.party?.name}
                  {active.party?.phone ? <span className="text-muted-foreground"> · {active.party.phone}</span> : null}
                </dd>
              </div>
              <div>
                <dt className="text-muted-foreground">Sale date</dt>
                <dd className="font-medium">{active.sale_date}</dd>
              </div>
              <div>
                <dt className="text-muted-foreground">Reference</dt>
                <dd className="font-medium">{active.reference ?? "—"}</dd>
              </div>
            </dl>

            {data.party ? (
              <Position
                items={[
                  ["Sale amount", data.party.amount],
                  ["Received", data.party.received],
                  ["Party due", data.party.due, true],
                ]}
              />
            ) : null}

            {data.profit !== null ? (
              <p className="text-sm">
                Profit (sale − purchase − expenses):{" "}
                <span className={`font-semibold tabular-nums ${data.profit.startsWith("-") ? "text-destructive" : ""}`}>{formatAmount(data.profit)}</span>
              </p>
            ) : null}

            {paying && data.party ? (
              <PaymentForm
                idPrefix="party-payment"
                outstanding={data.party.due}
                outstandingLabel="Party due"
                onSubmit={(input) => recordPayment.mutateAsync(input)}
                onCancel={() => setPaying(false)}
              />
            ) : null}

            {can("car.payment.view") ? (
              <PaymentsTable
                payments={data.payments}
                canReverse={can("car.payment.reverse") && !completed}
                onReverse={(id, reason) => reversePayment.mutateAsync({ id, reason })}
                onPrint={(id) => printPartyReceipt(carId, id)}
                printLabel="Receipt"
              />
            ) : null}

            {can("car.sale.reverse") && !completed ? (
              <ReverseButton label="sale" onReverse={(reason) => reverseSale.mutateAsync({ id: active.id, reason })} />
            ) : null}
          </>
        ) : null}

        {reversedSales.length > 0 ? (
          <details className="text-sm">
            <summary className="cursor-pointer text-muted-foreground">Reversed sales ({reversedSales.length})</summary>
            <ul className="mt-2 flex flex-col gap-2">
              {reversedSales.map((s) => (
                <li key={s.id} className="rounded-md border p-2">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="tabular-nums line-through">{formatAmount(s.amount)}</span>
                    <Badge variant="outline">Reversed</Badge>
                    <span className="text-muted-foreground">
                      {s.sale_date} · {s.party?.name}
                    </span>
                  </div>
                  <div className="text-xs text-muted-foreground">Reason: {s.reversal_reason}</div>
                </li>
              ))}
            </ul>
          </details>
        ) : null}
      </CardContent>
    </Card>
  );
}
