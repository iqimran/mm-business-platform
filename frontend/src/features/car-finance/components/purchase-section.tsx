"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { NativeSelect } from "@/components/common/native-select";
import { FieldError, FormAlert } from "@/components/common/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { usePermissions } from "@/features/auth/hooks";
import { dealerResource } from "@/features/car-master/config";
import { useActiveOptions } from "@/features/car-master/hooks";
import { applyApiErrors, errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import type { Purchase } from "../api";
import { usePurchase, useRecordPurchase, useReversePurchase } from "../hooks";
import { purchaseSchema, today, type PurchaseValues } from "../schemas";
import { DealerPayments } from "./dealer-payments";
import { ReverseButton } from "./reverse-button";

function PurchaseForm({ carId, defaultDealerId, onDone }: { carId: string; defaultDealerId?: string; onDone: () => void }) {
  const record = useRecordPurchase(carId);
  const dealers = useActiveOptions(dealerResource);
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<PurchaseValues>({
    resolver: zodResolver(purchaseSchema),
    defaultValues: { dealer_id: defaultDealerId ?? "", purchase_date: today(), amount: "", reference: "", notes: "" },
  });

  const submit = handleSubmit(async (v) => {
    try {
      await record.mutateAsync({ ...v, reference: v.reference || null, notes: v.notes || null });
      onDone();
    } catch (e) {
      applyApiErrors(e, setError, ["dealer_id", "purchase_date", "amount", "reference", "notes"]);
    }
  });

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      <FormAlert message={errors.root?.message} />
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-2">
          <Label htmlFor="purchase-dealer">Dealer</Label>
          <NativeSelect id="purchase-dealer" aria-invalid={errors.dealer_id ? true : undefined} {...register("dealer_id")}>
            <option value="">Select a dealer…</option>
            {(dealers.data ?? []).map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </NativeSelect>
          <FieldError id="purchase-dealer-error" message={errors.dealer_id?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="purchase-date">Purchase date</Label>
          <Input id="purchase-date" type="date" max={today()} aria-invalid={errors.purchase_date ? true : undefined} {...register("purchase_date")} />
          <FieldError id="purchase-date-error" message={errors.purchase_date?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="purchase-amount">Purchase amount</Label>
          <Input id="purchase-amount" inputMode="decimal" placeholder="700000.00" aria-invalid={errors.amount ? true : undefined} {...register("amount")} />
          <FieldError id="purchase-amount-error" message={errors.amount?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="purchase-reference">Reference / invoice no.</Label>
          <Input id="purchase-reference" aria-invalid={errors.reference ? true : undefined} {...register("reference")} />
          <FieldError id="purchase-reference-error" message={errors.reference?.message} />
        </div>
        <div className="flex flex-col gap-2 sm:col-span-2">
          <Label htmlFor="purchase-notes">Notes</Label>
          <Textarea id="purchase-notes" rows={2} aria-invalid={errors.notes ? true : undefined} {...register("notes")} />
          <FieldError id="purchase-notes-error" message={errors.notes?.message} />
        </div>
      </div>
      <p className="text-xs text-muted-foreground">Purchases cannot be edited later. To correct one, reverse it and record a new purchase.</p>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onDone}>
          Cancel
        </Button>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? "Recording…" : "Record purchase"}
        </Button>
      </div>
    </form>
  );
}

function PurchaseDetails({ purchase }: { purchase: Purchase }) {
  return (
    <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
      <div>
        <dt className="text-muted-foreground">Amount</dt>
        <dd className="text-lg font-semibold tabular-nums">{formatAmount(purchase.amount)}</dd>
      </div>
      <div>
        <dt className="text-muted-foreground">Dealer</dt>
        <dd className="font-medium">{purchase.dealer?.name}</dd>
      </div>
      <div>
        <dt className="text-muted-foreground">Purchase date</dt>
        <dd className="font-medium">{purchase.purchase_date}</dd>
      </div>
      <div>
        <dt className="text-muted-foreground">Reference</dt>
        <dd className="font-medium">{purchase.reference ?? "—"}</dd>
      </div>
      {purchase.notes ? (
        <div className="sm:col-span-2">
          <dt className="text-muted-foreground">Notes</dt>
          <dd className="whitespace-pre-wrap">{purchase.notes}</dd>
        </div>
      ) : null}
      <div className="text-xs text-muted-foreground sm:col-span-2">Recorded by {purchase.recorded_by?.name ?? "—"}</div>
    </dl>
  );
}

export function PurchaseSection({ carId, carDealerId, carSold }: { carId: string; carDealerId?: string; carSold: boolean }) {
  const { can } = usePermissions();
  const canView = can("car.purchase.view");
  const purchase = usePurchase(carId, canView);
  const reverse = useReversePurchase(carId);
  const [recording, setRecording] = useState(false);

  if (!canView) return null;

  const active = purchase.data?.active;
  const reversed = purchase.data?.history.filter((p) => p.is_reversed) ?? [];

  return (
    <Card>
      <CardHeader>
        <CardTitle>Purchase</CardTitle>
        <CardDescription>What was paid to the dealer for this car.</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {purchase.isPending ? <p className="text-sm text-muted-foreground">Loading…</p> : null}
        {purchase.isError ? <p className="text-sm text-destructive">{errorMessage(purchase.error)}</p> : null}

        {active ? (
          <>
            <PurchaseDetails purchase={active} />
            {can("car.purchase.reverse") && !carSold ? (
              <ReverseButton label="purchase" onReverse={(reason) => reverse.mutateAsync({ id: active.id, reason })} />
            ) : null}
            <DealerPayments carId={carId} />
          </>
        ) : purchase.data && !recording ? (
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-sm text-muted-foreground">No purchase recorded yet.</p>
            {can("car.purchase.create") && !carSold ? <Button onClick={() => setRecording(true)}>Record purchase</Button> : null}
          </div>
        ) : null}

        {recording && !active ? <PurchaseForm carId={carId} defaultDealerId={carDealerId} onDone={() => setRecording(false)} /> : null}

        {reversed.length > 0 ? (
          <details className="text-sm">
            <summary className="cursor-pointer text-muted-foreground">Reversed purchases ({reversed.length})</summary>
            <ul className="mt-2 flex flex-col gap-2">
              {reversed.map((p) => (
                <li key={p.id} className="rounded-md border p-2">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="tabular-nums line-through">{formatAmount(p.amount)}</span>
                    <Badge variant="outline">Reversed</Badge>
                    <span className="text-muted-foreground">
                      {p.purchase_date} · {p.dealer?.name}
                    </span>
                  </div>
                  <div className="text-xs text-muted-foreground">
                    Reason: {p.reversal_reason} — by {p.reversed_by?.name ?? "—"}
                  </div>
                </li>
              ))}
            </ul>
          </details>
        ) : null}
      </CardContent>
    </Card>
  );
}
