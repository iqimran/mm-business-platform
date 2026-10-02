"use client";

import { PageHeader } from "@/components/common/page-header";
import { usePermissions, useSession } from "@/features/auth/hooks";
import { ExpiryAlertCard } from "@/features/car-documents/components/expiry-alert-card";
import { CarDashboardCard } from "@/features/car-finance/components/car-dashboard-card";
import { RestaurantDashboardCard } from "@/features/restaurant-reports/components/restaurant-dashboard-card";

export default function DashboardPage() {
  const { data: session } = useSession();
  const { can } = usePermissions();

  if (!session) return null;

  // Each card shows only what the user may see (the API enforces it as well).
  const hasOverview = can("car.view") || can("car.document.view") || can("restaurant.report.view");

  return (
    <>
      <PageHeader title={`Welcome, ${session.user.name}`} description="Overview of your businesses." />

      <CarDashboardCard />
      <ExpiryAlertCard />
      <RestaurantDashboardCard />

      {hasOverview ? null : (
        <div className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
          No business overview is available for your account. Use the menu to open the pages you have access to.
        </div>
      )}
    </>
  );
}
