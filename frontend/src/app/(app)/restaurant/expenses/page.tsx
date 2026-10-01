"use client";

import { Plus } from "lucide-react";
import { useState } from "react";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { usePermissions } from "@/features/auth/hooks";
import { DailySummary } from "@/features/restaurant-expenses/components/daily-summary";
import { ExpenseForm } from "@/features/restaurant-expenses/components/expense-form";
import { ExpensesList } from "@/features/restaurant-expenses/components/expenses-list";

type Tab = "expenses" | "summary";

export default function RestaurantExpensesPage() {
  const { can } = usePermissions();
  const [tab, setTab] = useState<Tab>("expenses");
  const [adding, setAdding] = useState(false);

  if (!can("restaurant.expense.view")) return <Forbidden />;

  return (
    <>
      <PageHeader
        title="Restaurant expenses"
        description="Daily expenses by category. Corrections are made by reversing an expense and recording it again."
        actions={
          can("restaurant.expense.create") && !adding ? (
            <Button onClick={() => setAdding(true)}>
              <Plus aria-hidden />
              Record expense
            </Button>
          ) : null
        }
      />
      {adding ? <ExpenseForm onDone={() => setAdding(false)} /> : null}
      <div role="tablist" aria-label="Expense views" className="flex gap-1 border-b">
        {(
          [
            ["expenses", "Expenses"],
            ["summary", "Daily category summary"],
          ] as [Tab, string][]
        ).map(([key, label]) => (
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
      {tab === "expenses" ? <ExpensesList /> : <DailySummary />}
    </>
  );
}
