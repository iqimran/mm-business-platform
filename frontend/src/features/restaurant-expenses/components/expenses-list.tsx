"use client";

import Link from "next/link";
import { useState } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { Pager } from "@/components/common/pager";
import { Badge } from "@/components/ui/badge";
import { buttonVariants } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { usePermissions, useSession } from "@/features/auth/hooks";
import { PaymentStatusBadge } from "@/features/restaurant-common/components/payment-status-badge";
import { useDebouncedValue } from "@/hooks/use-debounced-value";
import { errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import type { ExpenseFilters } from "../api";
import { useExpenseCategories, useExpenses } from "../hooks";

const initial: ExpenseFilters = { page: 1, search: "", branchId: "", categoryId: "", dateFrom: "", dateTo: "", state: "", paymentStatus: "" };

export function ExpensesList() {
  const { can } = usePermissions();
  const { data: session } = useSession();
  const [filters, setFilters] = useState<ExpenseFilters>(initial);
  const search = useDebouncedValue(filters.search);
  const expenses = useExpenses({ ...filters, search });
  const canViewCategories = can("restaurant.expense_category.view");
  const categories = useExpenseCategories(false, canViewCategories);
  const set = (patch: Partial<ExpenseFilters>) => setFilters({ ...filters, ...patch, page: 1 });
  const filtered = JSON.stringify({ ...filters, page: 1 }) !== JSON.stringify(initial);
  const canPay = can("restaurant.supplier_payment.create");

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap gap-2">
        <Input type="search" placeholder="Description, reference…" aria-label="Search expenses" className="max-w-xs" value={filters.search} onChange={(e) => set({ search: e.target.value })} />
        <Input type="date" aria-label="From date" className="w-40" value={filters.dateFrom} onChange={(e) => set({ dateFrom: e.target.value })} />
        <Input type="date" aria-label="To date" className="w-40" value={filters.dateTo} min={filters.dateFrom || undefined} onChange={(e) => set({ dateTo: e.target.value })} />
        {canViewCategories ? (
          <NativeSelect aria-label="Category filter" className="w-44" value={filters.categoryId} onChange={(e) => set({ categoryId: e.target.value })}>
            <option value="">All categories</option>
            {(categories.data ?? []).map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
                {c.is_active ? "" : " (inactive)"}
              </option>
            ))}
          </NativeSelect>
        ) : null}
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
        <NativeSelect aria-label="State filter" className="w-36" value={filters.state} onChange={(e) => set({ state: e.target.value as ExpenseFilters["state"] })}>
          <option value="">All</option>
          <option value="active">Active</option>
          <option value="reversed">Reversed</option>
        </NativeSelect>
        <NativeSelect aria-label="Supplier payment filter" className="w-44" value={filters.paymentStatus} onChange={(e) => set({ paymentStatus: e.target.value as ExpenseFilters["paymentStatus"] })}>
          <option value="">Any payment</option>
          <option value="due">Due to supplier</option>
          <option value="unpaid">Unpaid</option>
          <option value="partial">Partially paid</option>
          <option value="paid">Paid</option>
        </NativeSelect>
      </div>

      {expenses.isPending ? <p className="text-sm text-muted-foreground">Loading expenses…</p> : null}
      {expenses.isError ? <p className="text-sm text-destructive">{errorMessage(expenses.error)}</p> : null}

      {expenses.data ? (
        <>
          <p className="text-sm">
            <span className="text-muted-foreground">Total of {expenses.data.summary.count} active expenses: </span>
            <span className="font-semibold tabular-nums">{formatAmount(expenses.data.summary.total)}</span>
            <span className="text-muted-foreground"> · paid </span>
            <span className="tabular-nums">{formatAmount(expenses.data.summary.paid)}</span>
            <span className="text-muted-foreground"> · due to suppliers </span>
            <span className={`font-semibold tabular-nums ${expenses.data.summary.supplier_due !== "0.00" ? "text-destructive" : ""}`}>
              {formatAmount(expenses.data.summary.supplier_due)}
            </span>
          </p>
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Date</TableHead>
                  <TableHead>Category</TableHead>
                  <TableHead className="hidden md:table-cell">Branch</TableHead>
                  <TableHead className="hidden md:table-cell">Description</TableHead>
                  <TableHead className="hidden lg:table-cell">Recorded by</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                  <TableHead className="text-right">Due</TableHead>
                  <TableHead className="w-40">
                    <span className="sr-only">Actions</span>
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {expenses.data.items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={8} className="py-8 text-center text-muted-foreground">
                      {filtered ? "No expenses match the filters." : "No expenses recorded yet."}
                    </TableCell>
                  </TableRow>
                ) : null}
                {expenses.data.items.map((e) => (
                  <TableRow key={e.id} className={e.is_reversed ? "text-muted-foreground" : undefined}>
                    <TableCell className="tabular-nums">
                      <Link href={`/restaurant/expenses/${e.id}`} className="underline-offset-2 hover:underline">
                        {e.expense_date}
                      </Link>
                    </TableCell>
                    <TableCell>{e.category?.name ?? "—"}</TableCell>
                    <TableCell className="hidden md:table-cell">{e.branch?.code ?? "—"}</TableCell>
                    <TableCell className="hidden whitespace-normal md:table-cell">
                      {e.description ?? "—"}
                      {e.reference ? <span className="text-muted-foreground"> · {e.reference}</span> : null}
                      {e.supplier ? <div className="text-xs text-muted-foreground">Supplier: {e.supplier.name}</div> : null}
                      {e.is_reversed ? <div className="text-xs">Reversed: {e.reversal_reason}</div> : null}
                    </TableCell>
                    <TableCell className="hidden lg:table-cell">{e.recorded_by?.name ?? "—"}</TableCell>
                    <TableCell className={`text-right tabular-nums ${e.is_reversed ? "line-through" : ""}`}>{formatAmount(e.amount)}</TableCell>
                    <TableCell className="text-right whitespace-normal">
                      {e.is_reversed ? "—" : (
                        <>
                          <div className="tabular-nums">{formatAmount(e.due)}</div>
                          {e.supplier ? <PaymentStatusBadge status={e.payment_status} /> : null}
                        </>
                      )}
                    </TableCell>
                    <TableCell className="whitespace-normal text-right">
                      {e.is_reversed ? (
                        <Badge variant="outline">Reversed</Badge>
                      ) : (
                        <Link href={`/restaurant/expenses/${e.id}`} className={buttonVariants({ variant: "ghost", size: "sm" })}>
                          {e.supplier && e.due !== "0.00" && canPay ? "Pay" : "Open"}
                        </Link>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          <Pager pagination={expenses.data.pagination} onPage={(page) => setFilters({ ...filters, page })} />
        </>
      ) : null}
    </div>
  );
}
