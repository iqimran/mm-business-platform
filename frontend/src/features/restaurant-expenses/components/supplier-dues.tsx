"use client";

import Link from "next/link";
import { Fragment, useState } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { Pager } from "@/components/common/pager";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { useSession } from "@/features/auth/hooks";
import { AmountSummary } from "@/features/restaurant-common/components/amount-summary";
import { PaymentStatusBadge } from "@/features/restaurant-common/components/payment-status-badge";
import { useDebouncedValue } from "@/hooks/use-debounced-value";
import { errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import { useExpenses, useSupplierDues } from "../hooks";

/** A supplier's unpaid / partly paid bills, oldest first, each linking to its pay screen. */
function DueBills({ supplierId, branchId }: { supplierId: string; branchId: string }) {
  const bills = useExpenses({ page: 1, search: "", branchId, categoryId: "", dateFrom: "", dateTo: "", state: "active", paymentStatus: "due", supplierId });

  if (bills.isPending) return <p className="text-sm text-muted-foreground">Loading bills…</p>;
  if (bills.isError) return <p className="text-sm text-destructive">{errorMessage(bills.error)}</p>;
  if (bills.data.items.length === 0) return <p className="text-sm text-muted-foreground">No unpaid bills.</p>;

  return (
    <ul className="flex flex-col divide-y rounded-md border bg-background text-sm">
      {bills.data.items.map((bill) => (
        <li key={bill.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
          <span>
            <span className="tabular-nums">{bill.expense_date}</span> · {bill.category?.name ?? "—"}
            {bill.reference ? <span className="text-muted-foreground"> · {bill.reference}</span> : null}
          </span>
          <span className="flex items-center gap-2">
            <span className="tabular-nums">
              {formatAmount(bill.due)} <span className="text-muted-foreground">of {formatAmount(bill.amount)}</span>
            </span>
            <PaymentStatusBadge status={bill.payment_status} />
            <Link href={`/restaurant/expenses/${bill.id}`} className="font-medium underline-offset-2 hover:underline">
              Pay
            </Link>
          </span>
        </li>
      ))}
      {bills.data.pagination.total > bills.data.items.length ? (
        <li className="px-3 py-2 text-xs text-muted-foreground">Showing the latest {bills.data.items.length} of {bills.data.pagination.total} bills.</li>
      ) : null}
    </ul>
  );
}

export function SupplierDues() {
  const { data: session } = useSession();
  const [filters, setFilters] = useState({ page: 1, search: "", branchId: "", onlyDue: true });
  const [open, setOpen] = useState<string | null>(null);
  const search = useDebouncedValue(filters.search);
  const dues = useSupplierDues({ ...filters, search });
  const set = (patch: Partial<typeof filters>) => setFilters({ ...filters, ...patch, page: 1 });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap gap-2">
        <Input type="search" placeholder="Supplier name, phone…" aria-label="Search suppliers" className="max-w-xs" value={filters.search} onChange={(e) => set({ search: e.target.value })} />
        {(session?.branches.length ?? 0) > 1 ? (
          <NativeSelect aria-label="Branch filter" className="w-44" value={filters.branchId} onChange={(e) => set({ branchId: e.target.value })}>
            <option value="">All my branches</option>
            {session?.branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.code} — {b.name}
              </option>
            ))}
          </NativeSelect>
        ) : null}
        <NativeSelect aria-label="Suppliers shown" className="w-48" value={filters.onlyDue ? "due" : "all"} onChange={(e) => set({ onlyDue: e.target.value === "due" })}>
          <option value="due">With dues only</option>
          <option value="all">All suppliers with bills</option>
        </NativeSelect>
      </div>

      {dues.isPending ? <p className="text-sm text-muted-foreground">Loading supplier dues…</p> : null}
      {dues.isError ? <p className="text-sm text-destructive">{errorMessage(dues.error)}</p> : null}
      {dues.data ? (
        <>
          <AmountSummary total={dues.data.totals.billed} paid={dues.data.totals.paid} due={dues.data.totals.due} labels={[`Billed (${dues.data.totals.suppliers} suppliers)`, "Paid", "Due to suppliers"]} />
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Supplier</TableHead>
                  <TableHead className="hidden text-right md:table-cell">Bills</TableHead>
                  <TableHead className="hidden text-right md:table-cell">Billed</TableHead>
                  <TableHead className="hidden text-right sm:table-cell">Paid</TableHead>
                  <TableHead className="text-right">Due</TableHead>
                  <TableHead className="hidden lg:table-cell">Oldest unpaid</TableHead>
                  <TableHead className="w-28">
                    <span className="sr-only">Actions</span>
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {dues.data.items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                      {filters.onlyDue ? "Nothing is due to suppliers." : "No supplier bills yet."}
                    </TableCell>
                  </TableRow>
                ) : null}
                {dues.data.items.map((row) => (
                  <Fragment key={row.supplier.id}>
                    <TableRow>
                      <TableCell className="font-medium whitespace-normal">
                        {row.supplier.name}
                        {row.supplier.phone ? <div className="text-xs text-muted-foreground">{row.supplier.phone}</div> : null}
                      </TableCell>
                      <TableCell className="hidden text-right tabular-nums md:table-cell">
                        {row.due_bills} / {row.bills}
                      </TableCell>
                      <TableCell className="hidden text-right tabular-nums md:table-cell">{formatAmount(row.billed)}</TableCell>
                      <TableCell className="hidden text-right tabular-nums sm:table-cell">{formatAmount(row.paid)}</TableCell>
                      <TableCell className={`text-right font-semibold tabular-nums ${row.due !== "0.00" ? "text-destructive" : ""}`}>{formatAmount(row.due)}</TableCell>
                      <TableCell className="hidden tabular-nums lg:table-cell">{row.oldest_due_date ?? "—"}</TableCell>
                      <TableCell className="text-right">
                        {row.due_bills > 0 ? (
                          <Button variant="ghost" size="sm" aria-expanded={open === row.supplier.id} onClick={() => setOpen(open === row.supplier.id ? null : row.supplier.id)}>
                            {open === row.supplier.id ? "Hide bills" : "Unpaid bills"}
                          </Button>
                        ) : null}
                      </TableCell>
                    </TableRow>
                    {open === row.supplier.id ? (
                      <TableRow>
                        <TableCell colSpan={7} className="bg-muted/30 whitespace-normal">
                          <DueBills supplierId={row.supplier.id} branchId={filters.branchId} />
                        </TableCell>
                      </TableRow>
                    ) : null}
                  </Fragment>
                ))}
              </TableBody>
            </Table>
          </div>
          <Pager pagination={dues.data.pagination} onPage={(page) => setFilters({ ...filters, page })} />
        </>
      ) : null}
    </div>
  );
}
