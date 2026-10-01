"use client";

import { Plus } from "lucide-react";
import Link from "next/link";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { buttonVariants } from "@/components/ui/button";
import { usePermissions } from "@/features/auth/hooks";
import { SalesList } from "@/features/restaurant-sales/components/sales-list";

export default function FoodSalesPage() {
  const { can } = usePermissions();

  if (!can("restaurant.sale.view")) return <Forbidden />;

  return (
    <>
      <PageHeader
        title="Food sales"
        description="Sales of your branches. Totals and dues are calculated by the server."
        actions={
          can("restaurant.sale.create") ? (
            <Link href="/restaurant/sales/new" className={buttonVariants()}>
              <Plus aria-hidden />
              New sale
            </Link>
          ) : null
        }
      />
      <SalesList />
    </>
  );
}
