"use client";

import { useState } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { useSession } from "@/features/auth/hooks";
import { today } from "@/features/restaurant-sales/schemas";
import { errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import type { CategoryTotal, SummaryFilters } from "../api";
import { useDailySummary, useExpenseCategories } from "../hooks";
import { rangeDays } from "../schemas";

function TotalsTable({ rows, total, label }: { rows: CategoryTotal[]; total: string; label: string }) {
  return (
    <table className="w-full text-sm">
      <tbody>
        {rows.map((row) => (
          <tr key={row.category_id}>
            <td className="py-1">{row.category}</td>
            <td className="py-1 text-right tabular-nums">{formatAmount(row.total)}</td>
          </tr>
        ))}
        <tr className="border-t font-semibold">
          <td className="py-1.5">{label}</td>
          <td className="py-1.5 text-right tabular-nums">{formatAmount(total)}</td>
        </tr>
      </tbody>
    </table>
  );
}

/** Daily category-wise expense totals (server-computed; reversed expenses excluded). */
export function DailySummary() {
  const { data: session } = useSession();
  const categories = useExpenseCategories(false);
  const [filters, setFilters] = useState<SummaryFilters>({ dateFrom: today(), dateTo: today(), branchId: "", categoryId: "" });
  const summary = useDailySummary(filters);
  const days = rangeDays(filters.dateFrom, filters.dateTo);
  const rangeError = days === null ? "Choose a valid date range." : days > 366 ? "Choose at most 366 days." : null;
  const set = (patch: Partial<SummaryFilters>) => setFilters({ ...filters, ...patch });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end gap-2">
        <Input type="date" aria-label="Summary from date" className="w-40" value={filters.dateFrom} max={today()} onChange={(e) => set({ dateFrom: e.target.value })} />
        <Input type="date" aria-label="Summary to date" className="w-40" value={filters.dateTo} min={filters.dateFrom} onChange={(e) => set({ dateTo: e.target.value })} />
        <NativeSelect aria-label="Summary category" className="w-44" value={filters.categoryId} onChange={(e) => set({ categoryId: e.target.value })}>
          <option value="">All categories</option>
          {(categories.data ?? []).map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </NativeSelect>
        {(session?.branches.length ?? 0) > 1 ? (
          <NativeSelect aria-label="Summary branch" className="w-44" value={filters.branchId} onChange={(e) => set({ branchId: e.target.value })}>
            <option value="">All branches</option>
            {session?.branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.code} — {b.name}
              </option>
            ))}
          </NativeSelect>
        ) : null}
      </div>

      {rangeError ? <p className="text-sm text-destructive">{rangeError}</p> : null}
      {!rangeError && summary.isPending ? <p className="text-sm text-muted-foreground">Loading summary…</p> : null}
      {summary.isError ? <p className="text-sm text-destructive">{errorMessage(summary.error)}</p> : null}

      {!rangeError && summary.data ? (
        summary.data.days.length === 0 ? (
          <p className="rounded-lg border p-6 text-center text-sm text-muted-foreground">No expenses in this period.</p>
        ) : (
          <div className="grid gap-4 lg:grid-cols-3">
            <div className="flex flex-col gap-4 lg:col-span-2">
              {summary.data.days.map((day) => (
                <Card key={day.date}>
                  <CardHeader>
                    <CardTitle className="text-base">Date: {day.date}</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <TotalsTable rows={day.categories} total={day.total} label="Total" />
                  </CardContent>
                </Card>
              ))}
            </div>
            <Card className="h-fit">
              <CardHeader>
                <CardTitle className="text-base">
                  {summary.data.date_from === summary.data.date_to ? "Day total" : `${summary.data.date_from} to ${summary.data.date_to}`}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <TotalsTable rows={summary.data.categories} total={summary.data.total} label={`Total (${summary.data.count} expenses)`} />
              </CardContent>
            </Card>
          </div>
        )
      ) : null}
    </div>
  );
}
