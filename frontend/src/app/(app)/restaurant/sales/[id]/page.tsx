"use client";

import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { buttonVariants } from "@/components/ui/button";
import { usePermissions } from "@/features/auth/hooks";
import { SaleDetail } from "@/features/restaurant-sales/components/sale-detail";
import { useSale } from "@/features/restaurant-sales/hooks";
import { errorMessage } from "@/lib/form-errors";

export default function FoodSalePage() {
  const { id } = useParams<{ id: string }>();
  const { can } = usePermissions();
  const sale = useSale(id);

  if (!can("restaurant.sale.view")) return <Forbidden />;

  return (
    <>
      <PageHeader
        title={sale.data ? `Sale ${sale.data.sale_no}` : "Food sale"}
        actions={
          <Link href="/restaurant/sales" className={buttonVariants({ variant: "outline" })}>
            <ArrowLeft aria-hidden />
            All sales
          </Link>
        }
      />
      {sale.isPending ? <p className="text-sm text-muted-foreground">Loading sale…</p> : null}
      {sale.isError ? <p className="text-sm text-destructive">{errorMessage(sale.error)}</p> : null}
      {sale.data ? <SaleDetail sale={sale.data} /> : null}
    </>
  );
}
