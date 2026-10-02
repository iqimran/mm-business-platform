"use client";

import { useState } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { Pager } from "@/components/common/pager";
import { Table, TableBody, TableCell, TableHeader, TableRow } from "@/components/ui/table";
import { usePermissions } from "@/features/auth/hooks";
import { useExpenseCategories } from "@/features/restaurant-expenses/hooks";
import { errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import type { CategoryRow, DayRow, ExpenseTotals, ReportQuery } from "../api";
import { useReport } from "../hooks";
import { nextSort } from "../period";
import { ExportButtons } from "./export-buttons";
import { SortHead, Totals, type Period } from "./report-controls";

export function ExpensesReport({ period }: { period: Period }) {
  const { can } = usePermissions();
  const canViewCategories = can("restaurant.expense_category.view");
  const categories = useExpenseCategories(false, canViewCategories);
  const [view, setView] = useState<{ groupBy: "day" | "category"; sort: string; direction: "asc" | "desc"; page: number; categoryId: string }>({
    groupBy: "category", sort: "total", direction: "desc", page: 1, categoryId: "",
  });
  const query: ReportQuery = { ...period, groupBy: view.groupBy, sort: view.sort, direction: view.direction, page: view.page, extra: { category_id: view.categoryId } };
  const report = useReport<DayRow | CategoryRow, ExpenseTotals>("expenses", query);
  const onSort = (column: string) => setView({ ...view, ...nextSort(view, column), page: 1 });
  const head = (label: string, column: string, align?: "right") => (
    <SortHead label={label} column={column} sort={view.sort} direction={view.direction} onSort={onSort} align={align} />
  );

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap gap-2">
        <NativeSelect aria-label="Expense view" className="w-44" value={view.groupBy}
          onChange={(e) => {
            const groupBy = e.target.value as "day" | "category";
            setView({ ...view, groupBy, sort: groupBy === "day" ? "day" : "total", direction: "desc", page: 1 });
          }}>
          <option value="category">By category</option>
          <option value="day">By day</option>
        </NativeSelect>
        {canViewCategories ? (
          <NativeSelect aria-label="Category filter" className="w-44" value={view.categoryId} onChange={(e) => setView({ ...view, categoryId: e.target.value, page: 1 })}>
            <option value="">All categories</option>
            {(categories.data ?? []).map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </NativeSelect>
        ) : null}
        <div className="ml-auto">
          <ExportButtons report="expenses" query={query} />
        </div>
      </div>

      {report.isPending ? <p className="text-sm text-muted-foreground">Loading report…</p> : null}
      {report.isError ? <p className="text-sm text-destructive">{errorMessage(report.error)}</p> : null}
      {report.data ? (
        <>
          <Totals items={[["Expenses", report.data.totals.count], ["Total", report.data.totals.total]]} />
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  {view.groupBy === "day" ? head("Date", "day") : head("Category", "category")}
                  {head("Expenses", "count", "right")}
                  {head("Total", "total", "right")}
                </TableRow>
              </TableHeader>
              <TableBody>
                {report.data.items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={3} className="py-8 text-center text-muted-foreground">
                      No expenses in this period.
                    </TableCell>
                  </TableRow>
                ) : null}
                {report.data.items.map((r) => (
                  <TableRow key={"date" in r ? r.date : r.category_id}>
                    <TableCell>{"date" in r ? <span className="tabular-nums">{r.date}</span> : r.category}</TableCell>
                    <TableCell className="text-right tabular-nums">{r.count}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatAmount(r.total)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          <Pager pagination={report.data.pagination} onPage={(page) => setView({ ...view, page })} />
          <p className="text-xs text-muted-foreground">For a day × category breakdown, see Restaurant → Expenses → Daily category summary.</p>
        </>
      ) : null}
    </div>
  );
}
