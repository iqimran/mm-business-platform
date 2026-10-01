"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import { useTimeline } from "../hooks";

const labels: Record<string, string> = {
  purchase: "Purchase",
  expense: "Expense",
  sale: "Sale",
  party_payment: "Payment received",
  dealer_payment: "Dealer payment",
  status_changed: "Status",
};

export function TimelineCard({ carId }: { carId: string }) {
  const timeline = useTimeline(carId);

  return (
    <Card>
      <CardHeader>
        <CardTitle>Timeline</CardTitle>
        <CardDescription>Everything recorded for this car, oldest first.</CardDescription>
      </CardHeader>
      <CardContent>
        {timeline.isPending ? <p className="text-sm text-muted-foreground">Loading…</p> : null}
        {timeline.isError ? <p className="text-sm text-destructive">{errorMessage(timeline.error)}</p> : null}
        {timeline.data && timeline.data.length === 0 ? <p className="text-sm text-muted-foreground">Nothing recorded yet.</p> : null}
        {timeline.data && timeline.data.length > 0 ? (
          <ol className="relative flex flex-col gap-3 border-l pl-4">
            {timeline.data.map((e, i) => (
              <li key={`${e.type}-${e.recorded_at}-${i}`} className="relative">
                <span
                  aria-hidden
                  className={`absolute -left-[21px] top-1.5 size-2.5 rounded-full ${e.is_reversal ? "bg-destructive" : "bg-primary"}`}
                />
                <div className="flex flex-wrap items-baseline justify-between gap-x-4 text-sm">
                  <span>
                    <span className="font-medium">{labels[e.type.replace(/_reversed$/, "")] ?? e.type}</span>
                    {e.is_reversal ? <span className="text-destructive"> (reversed)</span> : null} — {e.description}
                  </span>
                  {e.amount !== null ? (
                    <span className={`tabular-nums ${e.is_reversal ? "text-destructive" : ""}`}>{formatAmount(e.amount)}</span>
                  ) : null}
                </div>
                <div className="text-xs text-muted-foreground">
                  {e.date}
                  {e.by ? ` · ${e.by}` : ""}
                  {e.reference ? ` · Ref ${e.reference}` : ""}
                </div>
              </li>
            ))}
          </ol>
        ) : null}
      </CardContent>
    </Card>
  );
}
