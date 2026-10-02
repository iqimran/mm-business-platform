"use client";

import Link from "next/link";
import { useState } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { Pager } from "@/components/common/pager";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { bookingStatusLabels } from "@/features/restaurant-bookings/api";
import { BookingStatusBadge } from "@/features/restaurant-bookings/components/booking-status-badge";
import { PaymentStatusBadge } from "@/features/restaurant-common/components/payment-status-badge";
import { errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import type { BookingRow, BookingTotals, ReportQuery } from "../api";
import { useReport } from "../hooks";
import { nextSort } from "../period";
import { SortHead, Totals, type Period } from "./report-controls";

export function BookingsReport({ period }: { period: Period }) {
  const [view, setView] = useState({ sort: "booking_date", direction: "desc" as "asc" | "desc", page: 1, status: "" });
  const query: ReportQuery = { ...period, groupBy: "", sort: view.sort, direction: view.direction, page: view.page, extra: { status: view.status } };
  const report = useReport<BookingRow, BookingTotals>("bookings", query);
  const onSort = (column: string) => setView({ ...view, ...nextSort(view, column), page: 1 });
  const head = (label: string, column: string, align?: "right") => (
    <SortHead label={label} column={column} sort={view.sort} direction={view.direction} onSort={onSort} align={align} />
  );

  return (
    <div className="flex flex-col gap-4">
      <NativeSelect aria-label="Booking status filter" className="w-48" value={view.status} onChange={(e) => setView({ ...view, status: e.target.value, page: 1 })}>
        <option value="">Confirmed and completed</option>
        {Object.entries(bookingStatusLabels).map(([key, label]) => (
          <option key={key} value={key}>
            {label} only
          </option>
        ))}
      </NativeSelect>

      {report.isPending ? <p className="text-sm text-muted-foreground">Loading report…</p> : null}
      {report.isError ? <p className="text-sm text-destructive">{errorMessage(report.error)}</p> : null}
      {report.data ? (
        <>
          <Totals items={[["Bookings", report.data.totals.count], ["Booking amount", report.data.totals.agreed_amount], ["Payments received", report.data.totals.paid], ["Outstanding due", report.data.totals.due]]} />
          {report.data.totals.cancelled_count > 0 ? (
            <p className="text-xs text-muted-foreground">{report.data.totals.cancelled_count} cancelled booking(s) listed; they are not included in the totals.</p>
          ) : null}
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  {head("Booking", "booking_no")}
                  {head("Event date", "booking_date")}
                  <TableHead>Hall</TableHead>
                  <TableHead className="hidden md:table-cell">Customer</TableHead>
                  {head("Amount", "agreed_amount", "right")}
                  {head("Paid", "paid", "right")}
                  {head("Due", "due", "right")}
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {report.data.items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={8} className="py-8 text-center text-muted-foreground">
                      No bookings in this period.
                    </TableCell>
                  </TableRow>
                ) : null}
                {report.data.items.map((b) => (
                  <TableRow key={b.id} className={b.status === "cancelled" ? "text-muted-foreground" : undefined}>
                    <TableCell className="font-medium">
                      <Link href={`/restaurant/bookings/${b.id}`} className="underline-offset-2 hover:underline">
                        {b.booking_no}
                      </Link>
                    </TableCell>
                    <TableCell className="tabular-nums">
                      {b.booking_date} {b.start_time}–{b.end_time}
                    </TableCell>
                    <TableCell>
                      {b.hall} <span className="text-muted-foreground">· {b.branch.code}</span>
                    </TableCell>
                    <TableCell className="hidden md:table-cell">{b.customer}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatAmount(b.agreed_amount)}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatAmount(b.paid)}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatAmount(b.due)}</TableCell>
                    <TableCell>
                      <div className="flex flex-wrap gap-1">
                        <BookingStatusBadge booking={b} />
                        <PaymentStatusBadge status={b.payment_status} />
                      </div>
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
