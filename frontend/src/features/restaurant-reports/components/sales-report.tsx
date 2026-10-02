"use client";

import Link from "next/link";
import { useState } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { Pager } from "@/components/common/pager";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { PaymentStatusBadge } from "@/features/restaurant-common/components/payment-status-badge";
import { errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import type { DayRow, ReportQuery, SaleRow, SalesTotals } from "../api";
import { useReport } from "../hooks";
import { nextSort } from "../period";
import { ExportButtons } from "./export-buttons";
import { SortHead, Totals, type Period } from "./report-controls";
import { paymentStatusLabels } from "@/features/restaurant-common/payments";

export function SalesReport({ period }: { period: Period }) {
  const [view, setView] = useState<{ groupBy: "" | "day"; sort: string; direction: "asc" | "desc"; page: number; paymentStatus: string }>({
    groupBy: "day", sort: "day", direction: "desc", page: 1, paymentStatus: "",
  });
  const query: ReportQuery = { ...period, groupBy: view.groupBy, sort: view.sort, direction: view.direction, page: view.page, extra: { payment_status: view.paymentStatus } };
  const report = useReport<SaleRow | DayRow, SalesTotals>("sales", query);
  const onSort = (column: string) => setView({ ...view, ...nextSort(view, column), page: 1 });
  const head = (label: string, column: string, align?: "right") => (
    <SortHead label={label} column={column} sort={view.sort} direction={view.direction} onSort={onSort} align={align} />
  );

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap gap-2">
        <NativeSelect aria-label="Sales view" className="w-40" value={view.groupBy}
          onChange={(e) => {
            const groupBy = e.target.value as "" | "day";
            setView({ ...view, groupBy, sort: groupBy === "day" ? "day" : "sold_at", direction: "desc", page: 1 });
          }}>
          <option value="day">Daily totals</option>
          <option value="">Individual sales</option>
        </NativeSelect>
        <NativeSelect aria-label="Payment status filter" className="w-40" value={view.paymentStatus} onChange={(e) => setView({ ...view, paymentStatus: e.target.value, page: 1 })}>
          <option value="">Any payment</option>
          {Object.entries(paymentStatusLabels).map(([key, label]) => (
            <option key={key} value={key}>
              {label}
            </option>
          ))}
        </NativeSelect>
        <div className="ml-auto">
          <ExportButtons report="sales" query={query} />
        </div>
      </div>

      {report.isPending ? <p className="text-sm text-muted-foreground">Loading report…</p> : null}
      {report.isError ? <p className="text-sm text-destructive">{errorMessage(report.error)}</p> : null}
      {report.data ? (
        <>
          <Totals items={[["Sales", report.data.totals.count], ["Total sales", report.data.totals.total], ["Payment received", report.data.totals.paid], ["Outstanding due", report.data.totals.due]]} />
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                {view.groupBy === "day" ? (
                  <TableRow>
                    {head("Date", "day")}
                    {head("Sales", "count", "right")}
                    {head("Total", "total", "right")}
                    {head("Received", "paid", "right")}
                    {head("Due", "due", "right")}
                  </TableRow>
                ) : (
                  <TableRow>
                    {head("Sale", "sale_no")}
                    {head("Time", "sold_at")}
                    <TableHead className="hidden md:table-cell">Branch</TableHead>
                    <TableHead>Customer</TableHead>
                    {head("Total", "total", "right")}
                    {head("Paid", "paid", "right")}
                    {head("Due", "due", "right")}
                    <TableHead>Status</TableHead>
                  </TableRow>
                )}
              </TableHeader>
              <TableBody>
                {report.data.items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={8} className="py-8 text-center text-muted-foreground">
                      No sales in this period.
                    </TableCell>
                  </TableRow>
                ) : null}
                {view.groupBy === "day"
                  ? (report.data.items as DayRow[]).map((r) => (
                      <TableRow key={r.date}>
                        <TableCell className="tabular-nums">{r.date}</TableCell>
                        <TableCell className="text-right tabular-nums">{r.count}</TableCell>
                        <TableCell className="text-right tabular-nums">{formatAmount(r.total)}</TableCell>
                        <TableCell className="text-right tabular-nums">{formatAmount(r.paid ?? "0")}</TableCell>
                        <TableCell className="text-right tabular-nums">{formatAmount(r.due ?? "0")}</TableCell>
                      </TableRow>
                    ))
                  : (report.data.items as SaleRow[]).map((r) => (
                      <TableRow key={r.id}>
                        <TableCell className="font-medium">
                          <Link href={`/restaurant/sales/${r.id}`} className="underline-offset-2 hover:underline">
                            {r.sale_no}
                          </Link>
                        </TableCell>
                        <TableCell className="tabular-nums">{new Date(r.sold_at).toLocaleString()}</TableCell>
                        <TableCell className="hidden md:table-cell">{r.branch.code}</TableCell>
                        <TableCell>{r.customer ?? "Walk-in"}</TableCell>
                        <TableCell className="text-right tabular-nums">{formatAmount(r.total)}</TableCell>
                        <TableCell className="text-right tabular-nums">{formatAmount(r.paid)}</TableCell>
                        <TableCell className="text-right tabular-nums">{formatAmount(r.due)}</TableCell>
                        <TableCell>
                          <PaymentStatusBadge status={r.payment_status} />
                        </TableCell>
                      </TableRow>
                    ))}
              </TableBody>
            </Table>
          </div>
          <Pager pagination={report.data.pagination} onPage={(page) => setView({ ...view, page })} />
        </>
      ) : null}
    </div>
  );
}
