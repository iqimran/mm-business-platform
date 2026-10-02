"use client";

import Link from "next/link";
import { useState } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { Pager } from "@/components/common/pager";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AmountSummary } from "@/features/restaurant-common/components/amount-summary";
import { usePermissions } from "@/features/auth/hooks";
import { useDebouncedValue } from "@/hooks/use-debounced-value";
import { errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import { bookingStatusLabels, type BookingFilters, type BookingStatus } from "../api";
import { useBookings, useHallOptions } from "../hooks";
import { BookingPaymentBadge, BookingStatusBadge } from "./booking-status-badge";
import { type PaymentStatus, paymentStatusLabels } from "@/features/restaurant-common/payments";

const initial: BookingFilters = { page: 1, search: "", hallId: "", dateFrom: "", dateTo: "", status: "", paymentStatus: "" };

export function BookingsList() {
  const [filters, setFilters] = useState<BookingFilters>(initial);
  const search = useDebouncedValue(filters.search);
  const bookings = useBookings({ ...filters, search });
  const { can } = usePermissions();
  const canViewHalls = can("restaurant.hall.view");
  const halls = useHallOptions(false, canViewHalls);
  const set = (patch: Partial<BookingFilters>) => setFilters({ ...filters, ...patch, page: 1 });
  const filtered = JSON.stringify({ ...filters, page: 1 }) !== JSON.stringify(initial);

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap gap-2">
        <Input type="search" placeholder="Booking no., customer…" aria-label="Search bookings" className="max-w-xs" value={filters.search} onChange={(e) => set({ search: e.target.value })} />
        {canViewHalls ? (
          <NativeSelect aria-label="Hall filter" className="w-44" value={filters.hallId} onChange={(e) => set({ hallId: e.target.value })}>
            <option value="">All halls</option>
            {(halls.data ?? []).map((h) => (
              <option key={h.id} value={h.id}>
                {h.name}
                {h.branch ? ` — ${h.branch.code}` : ""}
              </option>
            ))}
          </NativeSelect>
        ) : null}
        <Input type="date" aria-label="From date" className="w-40" value={filters.dateFrom} onChange={(e) => set({ dateFrom: e.target.value })} />
        <Input type="date" aria-label="To date" className="w-40" value={filters.dateTo} min={filters.dateFrom || undefined} onChange={(e) => set({ dateTo: e.target.value })} />
        <NativeSelect aria-label="Booking status filter" className="w-36" value={filters.status} onChange={(e) => set({ status: e.target.value as BookingFilters["status"] })}>
          <option value="">Any status</option>
          {(Object.keys(bookingStatusLabels) as BookingStatus[]).map((s) => (
            <option key={s} value={s}>
              {bookingStatusLabels[s]}
            </option>
          ))}
        </NativeSelect>
        <NativeSelect aria-label="Payment status filter" className="w-40" value={filters.paymentStatus} onChange={(e) => set({ paymentStatus: e.target.value as BookingFilters["paymentStatus"] })}>
          <option value="">Any payment</option>
          {(Object.keys(paymentStatusLabels) as PaymentStatus[]).map((s) => (
            <option key={s} value={s}>
              {paymentStatusLabels[s]}
            </option>
          ))}
        </NativeSelect>
      </div>

      {bookings.isPending ? <p className="text-sm text-muted-foreground">Loading bookings…</p> : null}
      {bookings.isError ? <p className="text-sm text-destructive">{errorMessage(bookings.error)}</p> : null}

      {bookings.data ? (
        <>
          <AmountSummary
            total={bookings.data.summary.booking_total}
            paid={bookings.data.summary.paid}
            due={bookings.data.summary.due}
            labels={[`Agreed (${bookings.data.summary.count} bookings)`, "Received", "Outstanding"]}
          />
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Booking</TableHead>
                  <TableHead>Date & time</TableHead>
                  <TableHead>Hall</TableHead>
                  <TableHead className="hidden md:table-cell">Customer</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                  <TableHead className="hidden text-right sm:table-cell">Paid</TableHead>
                  <TableHead className="text-right">Due</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {bookings.data.items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={8} className="py-8 text-center text-muted-foreground">
                      {filtered ? "No bookings match the filters." : "No hall bookings yet."}
                    </TableCell>
                  </TableRow>
                ) : null}
                {bookings.data.items.map((b) => (
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
                      {b.hall?.name ?? "—"}
                      {b.branch ? <span className="text-muted-foreground"> · {b.branch.code}</span> : null}
                    </TableCell>
                    <TableCell className="hidden md:table-cell">{b.customer?.name ?? "—"}</TableCell>
                    <TableCell className={`text-right tabular-nums whitespace-normal ${b.status === "cancelled" ? "line-through" : ""}`}>
                      {formatAmount(b.booking_total)}
                      {b.food_package ? <div className="text-xs text-muted-foreground">incl. food {formatAmount(b.food_package.total)}</div> : null}
                    </TableCell>
                    <TableCell className="hidden text-right tabular-nums sm:table-cell">{formatAmount(b.paid)}</TableCell>
                    <TableCell className="text-right tabular-nums">{b.status === "cancelled" ? "—" : formatAmount(b.due)}</TableCell>
                    <TableCell>
                      <div className="flex flex-wrap gap-1">
                        <BookingStatusBadge booking={b} />
                        <BookingPaymentBadge booking={b} />
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          <Pager pagination={bookings.data.pagination} onPage={(page) => setFilters({ ...filters, page })} />
        </>
      ) : null}
    </div>
  );
}
