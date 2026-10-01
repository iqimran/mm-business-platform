"use client";

import { Plus } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { Pager } from "@/components/common/pager";
import { buttonVariants } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { usePermissions, useSession } from "@/features/auth/hooks";
import { carStatuses, carStatusLabels, type CarFilters } from "@/features/cars/api";
import { CarStatusBadge } from "@/features/cars/components/car-status-badge";
import { useCars } from "@/features/cars/hooks";
import { errorMessage } from "@/lib/form-errors";

export default function CarsPage() {
  const { can } = usePermissions();
  const { data: session } = useSession();
  const [filters, setFilters] = useState<CarFilters>({ page: 1, search: "", status: "", branchId: "" });
  const cars = useCars(filters);
  const branches = session?.branches ?? [];

  if (!can("car.view")) return <Forbidden />;

  return (
    <>
      <PageHeader
        title="Cars"
        description="Cars in the branches you can access."
        actions={
          can("car.create") ? (
            <Link href="/cars/new" className={buttonVariants()}>
              <Plus aria-hidden />
              New car
            </Link>
          ) : null
        }
      />

      <div className="flex flex-wrap gap-2">
        <Input
          type="search"
          placeholder="Search brand, model, chassis, registration…"
          aria-label="Search cars"
          className="max-w-sm"
          value={filters.search}
          onChange={(e) => setFilters({ ...filters, search: e.target.value, page: 1 })}
        />
        <NativeSelect
          aria-label="Status filter"
          className="w-44"
          value={filters.status}
          onChange={(e) => setFilters({ ...filters, status: e.target.value as CarFilters["status"], page: 1 })}
        >
          <option value="">All statuses</option>
          {carStatuses.map((s) => (
            <option key={s} value={s}>
              {carStatusLabels[s]}
            </option>
          ))}
        </NativeSelect>
        {branches.length > 1 ? (
          <NativeSelect
            aria-label="Branch filter"
            className="w-44"
            value={filters.branchId}
            onChange={(e) => setFilters({ ...filters, branchId: e.target.value, page: 1 })}
          >
            <option value="">All branches</option>
            {branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.code} — {b.name}
              </option>
            ))}
          </NativeSelect>
        ) : null}
      </div>

      {cars.isPending ? <p className="text-sm text-muted-foreground">Loading cars…</p> : null}
      {cars.isError ? <p className="text-sm text-destructive">{errorMessage(cars.error)}</p> : null}

      {cars.data ? (
        <>
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Car</TableHead>
                  <TableHead>Chassis</TableHead>
                  <TableHead className="hidden lg:table-cell">Registration</TableHead>
                  <TableHead className="hidden md:table-cell">Branch</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="hidden text-right sm:table-cell">Images</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {cars.data.items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                      No cars found.
                    </TableCell>
                  </TableRow>
                ) : null}
                {cars.data.items.map((car) => (
                  <TableRow key={car.id}>
                    <TableCell>
                      <Link href={`/cars/${car.id}`} className="font-medium underline-offset-4 hover:underline">
                        {car.brand} {car.model}
                      </Link>
                      <div className="text-xs text-muted-foreground">
                        {[car.model_year, car.color].filter(Boolean).join(" · ") || "—"}
                      </div>
                    </TableCell>
                    <TableCell className="font-mono text-xs">{car.chassis_number}</TableCell>
                    <TableCell className="hidden lg:table-cell">{car.registration_number ?? "—"}</TableCell>
                    <TableCell className="hidden md:table-cell">{car.branch?.code}</TableCell>
                    <TableCell>
                      <CarStatusBadge status={car.status} />
                    </TableCell>
                    <TableCell className="hidden text-right tabular-nums sm:table-cell">{car.images_count ?? 0}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          <Pager pagination={cars.data.pagination} onPage={(page) => setFilters({ ...filters, page })} />
        </>
      ) : null}
    </>
  );
}
