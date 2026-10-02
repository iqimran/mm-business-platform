"use client";

import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { buttonVariants } from "@/components/ui/button";
import { usePermissions } from "@/features/auth/hooks";
import { ExpenseDetail } from "@/features/restaurant-expenses/components/expense-detail";
import { useExpense } from "@/features/restaurant-expenses/hooks";
import { errorMessage } from "@/lib/form-errors";

export default function RestaurantExpensePage() {
  const { id } = useParams<{ id: string }>();
  const { can } = usePermissions();
  const expense = useExpense(id);

  if (!can("restaurant.expense.view")) return <Forbidden />;

  return (
    <>
      <PageHeader
        title={expense.data?.supplier ? `Bill from ${expense.data.supplier.name}` : "Expense"}
        actions={
          <Link href="/restaurant/expenses" className={buttonVariants({ variant: "outline" })}>
            <ArrowLeft aria-hidden />
            All expenses
          </Link>
        }
      />
      {expense.isPending ? <p className="text-sm text-muted-foreground">Loading expense…</p> : null}
      {expense.isError ? <p className="text-sm text-destructive">{errorMessage(expense.error)}</p> : null}
      {expense.data ? <ExpenseDetail expense={expense.data} /> : null}
    </>
  );
}
