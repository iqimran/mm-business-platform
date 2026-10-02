"use client";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import type { StreamSummary } from "../api";
import { useFinancialSummary } from "../hooks";
import type { Period } from "./report-controls";

function Figures({ rows }: { rows: [string, string | number][] }) {
  return (
    <dl className="flex flex-col gap-1.5 text-sm">
      {rows.map(([label, value]) => (
        <div key={label} className="flex justify-between gap-4">
          <dt className="text-muted-foreground">{label}</dt>
          <dd className="font-medium tabular-nums">{typeof value === "number" ? value : formatAmount(value)}</dd>
        </div>
      ))}
    </dl>
  );
}

function Stream({ title, data, countLabel }: { title: string; data: StreamSummary; countLabel: string }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">{title}</CardTitle>
      </CardHeader>
      <CardContent>
        <Figures
          rows={[
            [countLabel, data.count],
            ["Revenue", data.revenue],
            ["Received", data.received],
            ["Outstanding due", data.outstanding_due],
            ["Collected in period", data.collected_in_period],
          ]}
        />
      </CardContent>
    </Card>
  );
}

/** Revenue streams and expenses side by side — deliberately without a profit figure. */
export function SummaryView({ period }: { period: Period }) {
  const summary = useFinancialSummary(period.dateFrom, period.dateTo, period.branchId);

  if (!period.dateFrom || !period.dateTo) return <p className="text-sm text-muted-foreground">Choose a period.</p>;
  if (summary.isPending) return <p className="text-sm text-muted-foreground">Loading summary…</p>;
  if (summary.isError) return <p className="text-sm text-destructive">{errorMessage(summary.error)}</p>;

  const data = summary.data;
  const nothing = !data.food_sales && !data.hall_bookings && !data.expenses;

  return (
    <div className="flex flex-col gap-4">
      {nothing ? <p className="text-sm text-muted-foreground">You do not have access to any restaurant figures.</p> : null}
      <div className="grid gap-4 md:grid-cols-3">
        {data.food_sales ? <Stream title="Food sales" data={data.food_sales} countLabel="Sales" /> : null}
        {data.hall_bookings ? <Stream title="Hall bookings" data={data.hall_bookings} countLabel="Bookings" /> : null}
        {data.expenses ? (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Restaurant expenses</CardTitle>
            </CardHeader>
            <CardContent>
              <Figures rows={[["Expenses", data.expenses.count], ["Total", data.expenses.total]]} />
            </CardContent>
          </Card>
        ) : null}
      </div>
      <p className="text-xs text-muted-foreground">
        Revenue: active sales by sale date and non-cancelled bookings by event date in the period. Received and outstanding due refer to
        those sales/bookings. Collected in period: payments dated in the period. Expenses are shown separately; no profit is calculated
        because restaurant costs (e.g. food cost per dish) are not recorded.
      </p>
    </div>
  );
}
