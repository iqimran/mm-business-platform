"use client";

import { Forbidden, PageHeader } from "@/components/common/page-header";
import { usePermissions } from "@/features/auth/hooks";
import { SaleForm } from "@/features/restaurant-sales/components/sale-form";

export default function NewFoodSalePage() {
  const { can } = usePermissions();

  if (!can("restaurant.sale.create")) return <Forbidden />;

  return (
    <>
      <PageHeader title="New food sale" description="Add dishes, set quantities and record any payment received now." />
      <SaleForm />
    </>
  );
}
