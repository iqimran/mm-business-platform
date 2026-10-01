"use client";

import Link from "next/link";
import { useState } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { Pager } from "@/components/common/pager";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { useSession } from "@/features/auth/hooks";
import { useDebouncedValue } from "@/hooks/use-debounced-value";
import { errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import { paymentStatusLabels, type PaymentStatus, type SaleFilters } from "../api";
import { useSales } from "../hooks";
import { PaymentStatusBadge } from "./payment-status-badge";
import { SaleFigures } from "./sale-figures";

const initial: SaleFilters = { page: 1, search: "", branchId: "", dateFrom: "", dateTo: "", paymentStatus: "", state: "" };

export function SalesList() {
  const { data: session } = useSession();
  const [filters, setFilters] = useState<SaleFilters>(initial);
  const search = useDebouncedValue(filters.search);
  const sales = useSales({ ...filters, search });
  const set = (patch: Partial<SaleFilters>) => setFilters({ ...filters, ...patch, page: 1 });
  const filtered = JSON.stringify({ ...filters, page: 1 }) !== JSON.stringify(initial);

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap gap-2">
        <Input type="search" placeholder="Sale no., customer…" aria-label="Search sales" className="max-w-xs" value={filters.search} onChange={(e) => set({ search: e.target.value })} />
        {(session?.branches.length ?? 0) > 1 ? (
          <NativeSelect aria-label="Branch filter" className="w-44" value={filters.branchId} onChange={(e) => set({ branchId: e.target.value })}>
            <option value="">All branches</option>
            {session?.branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.code} — {b.name}
              </option>
            ))}
          </NativeSelect>
        ) : null}
        <Input type="date" aria-label="From date" className="w-40" value={filters.dateFrom} onChange={(e) => set({ dateFrom: e.target.value })} />
        <Input type="date" aria-label="To date" className="w-40" value={filters.dateTo} min={filters.dateFrom || undefined} onChange={(e) => set({ dateTo: e.target.value })} />
        <NativeSelect aria-label="Payment status filter" className="w-40" value={filters.paymentStatus} onChange={(e) => set({ paymentStatus: e.target.value as SaleFilters["paymentStatus"] })}>
          <option value="">Any payment</option>
          {(Object.keys(paymentStatusLabels) as PaymentStatus[]).map((s) => (
            <option key={s} value={s}>
              {paymentStatusLabels[s]}
            </option>
          ))}
        </NativeSelect>
        <NativeSelect aria-label="Sale state filter" className="w-36" value={filters.state} onChange={(e) => set({ state: e.target.value as SaleFilters["state"] })}>
          <option value="">All sales</option>
          <option value="active">Active</option>
          <option value="reversed">Reversed</option>
        </NativeSelect>
      </div>

      {sales.isPending ? <p className="text-sm text-muted-foreground">Loading sales…</p> : null}
      {sales.isError ? <p className="text-sm text-destructive">{errorMessage(sales.error)}</p> : null}

      {sales.data ? (
        <>
          <SaleFigures total={sales.data.summary.total} paid={sales.data.summary.paid} due={sales.data.summary.due} labels={[`Sales total (${sales.data.summary.count})`, "Received", "Outstanding"]} />
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Sale</TableHead>
                  <TableHead>Time</TableHead>
                  <TableHead className="hidden md:table-cell">Branch</TableHead>
                  <TableHead>Customer</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                  <TableHead className="hidden text-right sm:table-cell">Paid</TableHead>
                  <TableHead className="text-right">Due</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {sales.data.items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={8} className="py-8 text-center text-muted-foreground">
                      {filtered ? "No sales match the filters." : "No food sales yet."}
                    </TableCell>
                  </TableRow>
                ) : null}
                {sales.data.items.map((sale) => (
                  <TableRow key={sale.id} className={sale.is_reversed ? "text-muted-foreground" : undefined}>
                    <TableCell className="font-medium">
                      <Link href={`/restaurant/sales/${sale.id}`} className="underline-offset-2 hover:underline">
                        {sale.sale_no}
                      </Link>
                    </TableCell>
                    <TableCell className="tabular-nums">{new Date(sale.sold_at).toLocaleString()}</TableCell>
                    <TableCell className="hidden md:table-cell">{sale.branch?.code ?? "—"}</TableCell>
                    <TableCell>{sale.customer?.name ?? "Walk-in"}</TableCell>
                    <TableCell className={`text-right tabular-nums ${sale.is_reversed ? "line-through" : ""}`}>{formatAmount(sale.total)}</TableCell>
                    <TableCell className="hidden text-right tabular-nums sm:table-cell">{formatAmount(sale.paid)}</TableCell>
                    <TableCell className="text-right tabular-nums">{sale.is_reversed ? "—" : formatAmount(sale.due)}</TableCell>
                    <TableCell>
                      <PaymentStatusBadge sale={sale} />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          <Pager pagination={sales.data.pagination} onPage={(page) => setFilters({ ...filters, page })} />
        </>
      ) : null}
    </div>
  );
}
