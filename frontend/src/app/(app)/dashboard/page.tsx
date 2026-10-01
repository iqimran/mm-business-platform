"use client";

import { PageHeader } from "@/components/common/page-header";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { useSession } from "@/features/auth/hooks";
import { ExpiryAlertCard } from "@/features/car-documents/components/expiry-alert-card";
import { CarDashboardCard } from "@/features/car-finance/components/car-dashboard-card";

export default function DashboardPage() {
  const { data: session } = useSession();

  if (!session) return null;

  return (
    <>
      <PageHeader title={`Welcome, ${session.user.name}`} description="Your access in this platform." />

      <CarDashboardCard />
      <ExpiryAlertCard />

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Branches</CardTitle>
            <CardDescription>Branches you can access.</CardDescription>
          </CardHeader>
          <CardContent>
            {session.branches.length === 0 ? (
              <p className="text-sm text-muted-foreground">No branches assigned.</p>
            ) : (
              <ul className="flex flex-col gap-1 text-sm">
                {session.branches.map((branch) => (
                  <li key={branch.id}>
                    <span className="font-medium">{branch.code}</span> — {branch.name}
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Permissions</CardTitle>
            <CardDescription>{session.permissions.length} granted through your roles.</CardDescription>
          </CardHeader>
          <CardContent>
            {session.permissions.length === 0 ? (
              <p className="text-sm text-muted-foreground">No permissions granted.</p>
            ) : (
              <ul className="flex flex-wrap gap-1.5 text-xs">
                {session.permissions.map((permission) => (
                  <li key={permission} className="rounded-md bg-muted px-2 py-1 font-mono">
                    {permission}
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </>
  );
}
