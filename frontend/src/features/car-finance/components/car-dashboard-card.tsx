"use client";

import { useState } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { usePermissions } from "@/features/auth/hooks";
import { carStatusLabels, carStatuses } from "@/features/cars/api";
import { errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import { useCarDashboard } from "../hooks";

function monthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

function Stat({ label, value, tone }: { label: string; value: string; tone?: "bad" | "good" }) {
  return (
    <div>
      <div className="text-xs text-muted-foreground">{label}</div>
      <div
        className={`text-lg font-semibold tabular-nums ${tone === "bad" ? "text-destructive" : tone === "good" ? "text-emerald-700 dark:text-emerald-400" : ""}`}
      >
        {formatAmount(value)}
      </div>
    </div>
  );
}

/** Car business overview for the user's branches. All figures are computed by the API. */
export function CarDashboardCard() {
  const { can } = usePermissions();
  const enabled = can("car.view");
  const [range, setRange] = useState({ from: monthStart(), to: "" });
  const dashboard = useCarDashboard({ from: range.from || undefined, to: range.to || undefined }, enabled);

  if (!enabled) return null;
  const d = dashboard.data;

  return (
    <Card>
      <CardHeader>
        <CardTitle>Car business</CardTitle>
        <CardDescription>Across the branches you can access.</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-5">
        {dashboard.isError ? <p className="text-sm text-destructive">{errorMessage(dashboard.error)}</p> : null}
        {d ? (
          <>
            <div className="flex flex-wrap gap-2 text-xs">
              {carStatuses.map((s) => (
                <span key={s} className="rounded-md bg-muted px-2 py-1">
                  {carStatusLabels[s]}: <span className="font-semibold tabular-nums">{d.cars_by_status[s] ?? 0}</span>
                </span>
              ))}
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
              {d.stock ? <Stat label={`Investment in stock (${d.stock.cars} cars)`} value={d.stock.total_investment} /> : null}
              {d.receivables ? <Stat label="Party due (customers owe)" value={d.receivables.party_due} tone="bad" /> : null}
              {d.payables ? <Stat label="Dealer payable (we owe)" value={d.payables.dealer_payable} tone="bad" /> : null}
            </div>

            {d.sales ? (
              <div className="flex flex-col gap-3 rounded-lg border p-3">
                <div className="flex flex-wrap items-end gap-3">
                  <div className="text-sm font-medium">Sales in period</div>
                  <div className="flex items-center gap-2">
                    <Label htmlFor="dash-from" className="text-xs">
                      From
                    </Label>
                    <Input id="dash-from" type="date" className="h-7 w-36" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} />
                    <Label htmlFor="dash-to" className="text-xs">
                      To
                    </Label>
                    <Input id="dash-to" type="date" className="h-7 w-36" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} />
                  </div>
                </div>
                <div className="grid gap-4 sm:grid-cols-3">
                  <div>
                    <div className="text-xs text-muted-foreground">Cars sold</div>
                    <div className="text-lg font-semibold tabular-nums">{d.sales.count}</div>
                  </div>
                  <Stat label="Sales total" value={d.sales.sale_total} />
                  {d.sales.profit ? (
                    <Stat label="Profit" value={d.sales.profit.profit} tone={d.sales.profit.profit.startsWith("-") ? "bad" : "good"} />
                  ) : null}
                </div>
              </div>
            ) : null}
          </>
        ) : null}
      </CardContent>
    </Card>
  );
}
