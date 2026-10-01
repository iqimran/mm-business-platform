"use client";

import { useState } from "react";
import { FormAlert } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { usePermissions } from "@/features/auth/hooks";
import { carStatusLabels, type CarStatus } from "@/features/cars/api";
import { errorMessage } from "@/lib/form-errors";
import { useChangeCarStatus } from "../hooks";

const actionLabels: Partial<Record<CarStatus, string>> = {
  IN_STOCK: "Move to stock",
  PREPARATION: "Start preparation",
  READY_FOR_SALE: "Mark ready for sale",
  COMPLETED: "Complete sale",
  SOLD: "Reopen sale",
};

/**
 * Buttons for the transitions the API allows from the current status.
 * Sold is reached only by recording a sale; reopening a completed sale needs a reason.
 */
export function StatusControl({ carId, status, nextStatuses }: { carId: string; status: CarStatus; nextStatuses: CarStatus[] }) {
  const { can } = usePermissions();
  const change = useChangeCarStatus(carId);
  const [error, setError] = useState<string>();
  const [reopening, setReopening] = useState(false);
  const [reason, setReason] = useState("");

  if (!can("car.status.update") || nextStatuses.length === 0) return null;

  const move = (to: CarStatus, withReason?: string) => {
    setError(undefined);
    change.mutate(
      { status: to, reason: withReason },
      {
        onSuccess: () => {
          setReopening(false);
          setReason("");
        },
        onError: (e) => setError(errorMessage(e)),
      },
    );
  };

  return (
    <div className="flex flex-col gap-2 rounded-lg border p-3">
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <span className="text-muted-foreground">Status: {carStatusLabels[status]} →</span>
        {nextStatuses.map((to) =>
          to === "SOLD" && status === "COMPLETED" ? (
            <Button key={to} variant="outline" size="sm" disabled={change.isPending} onClick={() => setReopening(true)}>
              {actionLabels[to]}
            </Button>
          ) : (
            <Button key={to} variant={to === "COMPLETED" ? "default" : "outline"} size="sm" disabled={change.isPending} onClick={() => move(to)}>
              {actionLabels[to] ?? carStatusLabels[to]}
            </Button>
          ),
        )}
      </div>
      {reopening ? (
        <div className="flex flex-col gap-2">
          <Label htmlFor="reopen-reason">Reason for reopening</Label>
          <Input id="reopen-reason" value={reason} maxLength={500} autoFocus onChange={(e) => setReason(e.target.value)} />
          <div className="flex justify-end gap-2">
            <Button variant="outline" size="sm" onClick={() => setReopening(false)}>
              Cancel
            </Button>
            <Button size="sm" disabled={change.isPending || reason.trim().length < 5} onClick={() => move("SOLD", reason.trim())}>
              Reopen
            </Button>
          </div>
        </div>
      ) : null}
      <FormAlert message={error} />
    </div>
  );
}
