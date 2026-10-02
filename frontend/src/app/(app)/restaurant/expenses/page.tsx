"use client";

import { Plus } from "lucide-react";
import { useState } from "react";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { usePermissions } from "@/features/auth/hooks";
import { missingPermissions } from "@/features/restaurant-common/permissions";
import { Tabs } from "@/features/restaurant-common/components/tabs";
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
          missingPermissions(can, "newExpense").length === 0 && !adding ? (
            <Button onClick={() => setAdding(true)}>
              <Plus aria-hidden />
              Record expense
            </Button>
          ) : null
        }
      />
      {adding ? <ExpenseForm onDone={() => setAdding(false)} /> : null}
      <Tabs
        label="Expense views"
        tabs={[
          ["expenses", "Expenses"],
          ["summary", "Daily category summary"],
        ]}
        value={tab}
        onChange={setTab}
      />
      {tab === "expenses" ? <ExpensesList /> : <DailySummary />}
    </>
  );
}
