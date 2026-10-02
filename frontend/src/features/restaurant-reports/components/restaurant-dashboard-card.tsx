"use client";

import Link from "next/link";
import { useState } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { usePermissions } from "@/features/auth/hooks";
import { errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import type { StreamSummary } from "../api";
import { useFinancialSummary } from "../hooks";
import { presetRange, type PeriodPreset } from "../period";

const periods: [PeriodPreset, string][] = [
  ["today", "Today"],
  ["this_month", "This month"],
  ["last_month", "Last month"],
];

function Figure({ label, value, tone }: { label: string; value: string; tone?: "bad" }) {
  return (
    <div>
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className={`text-lg font-semibold tabular-nums ${tone === "bad" && value !== "0.00" ? "text-destructive" : ""}`}>{formatAmount(value)}</div>
    </div>
  );
}

function Stream({ title, countLabel, data, href, note }: { title: string; countLabel: string; data: StreamSummary; href: string; note?: string }) {
  return (
    <div className="flex flex-col gap-3 rounded-lg border p-3">
      <div className="flex items-baseline justify-between gap-2">
        <Link href={href} className="font-medium underline-offset-2 hover:underline">
          {title}
        </Link>
        <span className="text-xs text-muted-foreground">
          {data.count} {countLabel}
        </span>
      </div>
      <div className="grid grid-cols-3 gap-2">
        <Figure label="Revenue" value={data.revenue} />
        <Figure label="Received" value={data.received} />
        <Figure label="Due" value={data.outstanding_due} tone="bad" />
      </div>
      {note ? <p className="text-xs text-muted-foreground">{note}</p> : null}
    </div>
  );
}

/**
 * Restaurant overview for the user's branches (same figures as Restaurant reports → Summary).
 * Revenue streams and expenses are shown separately; no profit is calculated.
 */
export function RestaurantDashboardCard() {
  const { can } = usePermissions();
  const enabled = can("restaurant.report.view");
  const [preset, setPreset] = useState<PeriodPreset>("this_month");
  const range = presetRange(preset);
  const summary = useFinancialSummary(enabled ? range.from : "", range.to, "");

  if (!enabled) return null;
  const d = summary.data;
  const nothing = d && !d.food_sales && !d.hall_bookings && !d.expenses;

  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
        <div>
          <CardTitle>Restaurant business</CardTitle>
          <CardDescription>
            {range.from === range.to ? range.from : `${range.from} to ${range.to}`} · all your branches
          </CardDescription>
        </div>
        <NativeSelect aria-label="Restaurant period" className="w-36" value={preset} onChange={(e) => setPreset(e.target.value as PeriodPreset)}>
          {periods.map(([key, label]) => (
            <option key={key} value={key}>
              {label}
            </option>
          ))}
        </NativeSelect>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {summary.isPending ? <p className="text-sm text-muted-foreground">Loading restaurant figures…</p> : null}
        {summary.isError ? <p className="text-sm text-destructive">{errorMessage(summary.error)}</p> : null}
        {nothing ? <p className="text-sm text-muted-foreground">You do not have access to any restaurant figures.</p> : null}
        {d && !nothing ? (
          <>
            <div className="grid gap-3 lg:grid-cols-3">
              {d.food_sales ? <Stream title="Food sales" countLabel="sales" data={d.food_sales} href="/restaurant/sales" /> : null}
              {d.hall_bookings ? (
                <Stream
                  title="Hall bookings"
                  countLabel="bookings"
                  data={d.hall_bookings}
                  href="/restaurant/bookings"
                  note={`Hall charges ${formatAmount(d.hall_bookings.hall_charges)} · Food packages ${formatAmount(d.hall_bookings.food_packages)}`}
                />
              ) : null}
              {d.expenses ? (
                <div className="flex flex-col gap-3 rounded-lg border p-3">
                  <div className="flex items-baseline justify-between gap-2">
                    <Link href="/restaurant/expenses" className="font-medium underline-offset-2 hover:underline">
                      Expenses
                    </Link>
                    <span className="text-xs text-muted-foreground">{d.expenses.count} entries</span>
                  </div>
                  <div className="grid grid-cols-2 gap-2">
                    <Figure label="Total" value={d.expenses.total} />
                    <Figure label="Supplier dues" value={d.expenses.supplier_due} tone="bad" />
                  </div>
                </div>
              ) : null}
            </div>
            <p className="text-xs text-muted-foreground">
              Bookings count by event date. Expenses are shown separately (no profit is calculated).{" "}
              <Link href="/restaurant/reports" className="underline-offset-2 hover:underline">
                Open restaurant reports
              </Link>
            </p>
          </>
        ) : null}
      </CardContent>
    </Card>
  );
}
