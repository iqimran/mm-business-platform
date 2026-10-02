"use client";

import { useState } from "react";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { usePermissions } from "@/features/auth/hooks";
import { BookingsReport } from "@/features/restaurant-reports/components/bookings-report";
import { ExpensesReport } from "@/features/restaurant-reports/components/expenses-report";
import { PeriodFilter, type Period } from "@/features/restaurant-reports/components/report-controls";
import { SalesReport } from "@/features/restaurant-reports/components/sales-report";
import { SummaryView } from "@/features/restaurant-reports/components/summary-view";
import { presetRange } from "@/features/restaurant-reports/period";

type Tab = "summary" | "sales" | "bookings" | "expenses";

export default function RestaurantReportsPage() {
  const { can } = usePermissions();
  const month = presetRange("this_month");
  const [period, setPeriod] = useState<Period>({ dateFrom: month.from, dateTo: month.to, branchId: "" });
  const [tab, setTab] = useState<Tab>("summary");

  if (!can("restaurant.report.view")) return <Forbidden />;

  // Tabs follow the area permissions the API enforces.
  const tabs = ([
    ["summary", "Summary", true],
    ["sales", "Food sales", can("restaurant.sale.view")],
    ["bookings", "Hall bookings", can("restaurant.booking.view")],
    ["expenses", "Expenses", can("restaurant.expense.view")],
  ] as [Tab, string, boolean][]).filter(([, , allowed]) => allowed);

  return (
    <>
      <PageHeader title="Restaurant reports" description="Food sales, hall bookings and expenses for your branches, calculated by the server." />
      <PeriodFilter value={period} onChange={setPeriod} />
      <div role="tablist" aria-label="Report" className="flex gap-1 border-b">
        {tabs.map(([key, label]) => (
          <button
            key={key}
            role="tab"
            type="button"
            aria-selected={tab === key}
            className={`-mb-px border-b-2 px-3 py-2 text-sm ${tab === key ? "border-primary font-medium" : "border-transparent text-muted-foreground hover:text-foreground"}`}
            onClick={() => setTab(key)}
          >
            {label}
          </button>
        ))}
      </div>
      {tab === "summary" ? <SummaryView period={period} /> : null}
      {tab === "sales" ? <SalesReport period={period} /> : null}
      {tab === "bookings" ? <BookingsReport period={period} /> : null}
      {tab === "expenses" ? <ExpensesReport period={period} /> : null}
    </>
  );
}
