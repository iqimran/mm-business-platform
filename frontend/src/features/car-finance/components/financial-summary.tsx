"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { errorMessage } from "@/lib/form-errors";
import { formatAmount } from "@/lib/money";
import { useFinancialSummary } from "../hooks";

/**
 * Display only: every figure comes from the API (GET /cars/{id}/financial-summary).
 * Blocks the user may not see are null and are not rendered.
 */
function Figure({ label, value, emphasis, tone }: { label: string; value: string | null; emphasis?: boolean; tone?: "good" | "bad" }) {
  if (value === null) return null;

  return (
    <div>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd
        className={`tabular-nums ${emphasis ? "text-lg font-semibold" : "font-medium"} ${
          tone === "bad" ? "text-destructive" : tone === "good" ? "text-emerald-700 dark:text-emerald-400" : ""
        }`}
      >
        {formatAmount(value)}
      </dd>
    </div>
  );
}

function Block({ title, hint, children }: { title: string; hint: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-2 rounded-lg border p-3">
      <div>
        <div className="text-sm font-medium">{title}</div>
        <div className="text-xs text-muted-foreground">{hint}</div>
      </div>
      <dl className="grid grid-cols-3 gap-2">{children}</dl>
    </div>
  );
}

export function FinancialSummaryCard({ carId }: { carId: string }) {
  const summary = useFinancialSummary(carId);
  const s = summary.data;

  if (summary.isError) return <p className="text-sm text-destructive">{errorMessage(summary.error)}</p>;
  if (!s || (!s.costs && !s.party && !s.dealer && s.profit === null)) return null;

  const profitTone = s.profit === null ? undefined : s.profit.startsWith("-") ? "bad" : "good";

  return (
    <Card>
      <CardHeader>
        <CardTitle>Financial summary</CardTitle>
        <CardDescription>Calculated by the system from active records (reversed entries excluded).</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3 lg:grid-cols-2">
        {s.costs ? (
          <Block title="Investment" hint="Purchase cost + car expenses">
            <Figure label="Purchase cost" value={s.costs.purchase_cost} />
            <Figure label="Expenses" value={s.costs.expenses_total} />
            <Figure label="Total investment" value={s.costs.total_investment} emphasis />
          </Block>
        ) : null}
        {s.profit !== null || s.party ? (
          <Block title="Profit" hint="Sale price − purchase cost − expenses">
            <Figure label="Sale price" value={s.party?.amount ?? null} />
            <Figure label="Profit" value={s.profit} emphasis tone={profitTone} />
          </Block>
        ) : null}
        {s.party ? (
          <Block title="Party (customer)" hint="Sale amount − payments received">
            <Figure label="Sale amount" value={s.party.amount} />
            <Figure label="Received" value={s.party.received} />
            <Figure label="Party due" value={s.party.due} emphasis tone={s.party.is_settled ? "good" : "bad"} />
          </Block>
        ) : null}
        {s.dealer ? (
          <Block title="Dealer" hint="Purchase amount − payments made">
            <Figure label="Purchase amount" value={s.dealer.purchase_amount} />
            <Figure label="Paid" value={s.dealer.paid} />
            <Figure label="Dealer payable" value={s.dealer.payable} emphasis tone={s.dealer.is_settled ? "good" : "bad"} />
          </Block>
        ) : null}
      </CardContent>
    </Card>
  );
}
