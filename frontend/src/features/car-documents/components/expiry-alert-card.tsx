"use client";

import { TriangleAlert } from "lucide-react";
import Link from "next/link";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { usePermissions } from "@/features/auth/hooks";
import { useExpirySummary } from "../hooks";

/** Dashboard alert: expired and soon-expiring car documents in the user's branches. */
export function ExpiryAlertCard() {
  const { can } = usePermissions();
  const canView = can("car.document.view");
  const summary = useExpirySummary(canView);

  if (!canView || !summary.data) return null;

  const { expired, expiring, alert_days } = summary.data;
  const attention = expired + expiring > 0;

  return (
    <Card className={attention ? "border-amber-500/40" : undefined}>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          {attention ? <TriangleAlert className="size-4 text-amber-600" aria-hidden /> : null}
          Car documents
        </CardTitle>
        <CardDescription>Fitness, tax token, insurance and other expiries.</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <div className="grid grid-cols-2 gap-3 text-sm">
          <div>
            <div className="text-muted-foreground">Expired</div>
            <div className={`text-2xl font-semibold tabular-nums ${expired > 0 ? "text-destructive" : ""}`}>{expired}</div>
          </div>
          <div>
            <div className="text-muted-foreground">Expiring ≤ {alert_days} days</div>
            <div className={`text-2xl font-semibold tabular-nums ${expiring > 0 ? "text-amber-700 dark:text-amber-400" : ""}`}>{expiring}</div>
          </div>
        </div>
        <Link href="/car-documents" className="w-fit text-sm font-medium underline-offset-4 hover:underline">
          View document expiry list
        </Link>
      </CardContent>
    </Card>
  );
}
