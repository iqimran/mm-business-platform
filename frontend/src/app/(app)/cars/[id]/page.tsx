"use client";

import { ArrowLeft, Pencil, Trash2 } from "lucide-react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { FormAlert, PageHeader } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { usePermissions } from "@/features/auth/hooks";
import type { Car } from "@/features/cars/api";
import { DocumentsSection } from "@/features/car-documents/components/documents-section";
import { ExpensesSection } from "@/features/car-finance/components/expenses-section";
import { FinancialSummaryCard } from "@/features/car-finance/components/financial-summary";
import { TimelineCard } from "@/features/car-finance/components/timeline";
import { PurchaseSection } from "@/features/car-finance/components/purchase-section";
import { SaleSection } from "@/features/car-finance/components/sale-section";
import { StatusControl } from "@/features/car-finance/components/status-control";
import { CarForm } from "@/features/cars/components/car-form";
import { CarImages } from "@/features/cars/components/car-images";
import { CarStatusBadge } from "@/features/cars/components/car-status-badge";
import { useCar, useDeleteCar, useUpdateCar } from "@/features/cars/hooks";
import { errorMessage } from "@/lib/form-errors";

function Details({ car }: { car: Car }) {
  const rows: [string, string | number | null | undefined][] = [
    ["Branch", car.branch ? `${car.branch.code} — ${car.branch.name}` : null],
    ["Dealer", car.dealer?.name],
    ["Model year", car.model_year],
    ["Color", car.color],
    ["Chassis number", car.chassis_number],
    ["Engine number", car.engine_number],
    ["Registration number", car.registration_number],
    ["Registration date", car.registration_date],
    ["Mileage", car.mileage_km != null ? `${car.mileage_km.toLocaleString()} km` : null],
  ];

  return (
    <Card>
      <CardHeader>
        <CardTitle>Details</CardTitle>
      </CardHeader>
      <CardContent>
        <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
          {rows.map(([label, value]) => (
            <div key={label}>
              <dt className="text-muted-foreground">{label}</dt>
              <dd className="font-medium">{value ?? "—"}</dd>
            </div>
          ))}
          {car.notes ? (
            <div className="sm:col-span-2">
              <dt className="text-muted-foreground">Notes</dt>
              <dd className="whitespace-pre-wrap">{car.notes}</dd>
            </div>
          ) : null}
        </dl>
      </CardContent>
    </Card>
  );
}

export default function CarPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = usePermissions();
  const car = useCar(id);
  const updateCar = useUpdateCar(id);
  const deleteCar = useDeleteCar();
  const [editing, setEditing] = useState(false);
  const [error, setError] = useState<string>();

  if (car.isPending) return <p className="text-sm text-muted-foreground">Loading…</p>;
  if (car.isError) return <p className="text-sm text-destructive">{errorMessage(car.error)}</p>;

  const data = car.data;
  const canUpdate = can("car.update");

  const onDelete = () => {
    if (!window.confirm(`Delete ${data.brand} ${data.model} (${data.chassis_number})? This cannot be undone.`)) return;
    setError(undefined);
    deleteCar.mutate(data.id, {
      onSuccess: () => router.push("/cars"),
      onError: (e) => setError(errorMessage(e)),
    });
  };

  return (
    <>
      <Link href="/cars" className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" aria-hidden />
        All cars
      </Link>
      <PageHeader
        title={`${data.brand} ${data.model}`}
        description={data.chassis_number}
        actions={
          <>
            <CarStatusBadge status={data.status} />
            {canUpdate && !editing ? (
              <Button variant="outline" onClick={() => setEditing(true)}>
                <Pencil aria-hidden />
                Edit
              </Button>
            ) : null}
            {can("car.delete") && !editing ? (
              <Button variant="destructive" onClick={onDelete} disabled={deleteCar.isPending}>
                <Trash2 aria-hidden />
                Delete
              </Button>
            ) : null}
          </>
        }
      />
      <FormAlert message={error} />
      <StatusControl carId={data.id} status={data.status} nextStatuses={data.next_statuses ?? []} />

      {editing ? (
        <CarForm
          car={data}
          submitLabel="Save changes"
          onCancel={() => setEditing(false)}
          onSubmit={async (input) => {
            await updateCar.mutateAsync(input);
            setEditing(false);
          }}
        />
      ) : (
        <Details car={data} />
      )}

      <FinancialSummaryCard carId={data.id} />
      <DocumentsSection carId={data.id} />
      <PurchaseSection carId={data.id} carDealer={data.dealer} carSold={data.status === "SOLD" || data.status === "COMPLETED"} />
      <ExpensesSection carId={data.id} carCompleted={data.status === "COMPLETED"} />
      <SaleSection carId={data.id} status={data.status} />

      <TimelineCard carId={data.id} />
      <CarImages car={data} canEdit={canUpdate} />

    </>
  );
}
