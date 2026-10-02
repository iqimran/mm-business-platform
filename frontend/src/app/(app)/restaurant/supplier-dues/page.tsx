"use client";

import { Forbidden, PageHeader } from "@/components/common/page-header";
import { usePermissions } from "@/features/auth/hooks";
import { SupplierDues } from "@/features/restaurant-expenses/components/supplier-dues";

export default function SupplierDuesPage() {
  const { can } = usePermissions();

  if (!can("restaurant.expense.view")) return <Forbidden />;

  return (
    <>
      <PageHeader title="Supplier dues" description="What is owed to each supplier for restaurant expenses (bills), calculated by the server." />
      <SupplierDues />
    </>
  );
}
