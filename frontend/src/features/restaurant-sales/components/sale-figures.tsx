import { formatAmount } from "@/lib/money";

/** Total / Paid / Due summary (values are backend-computed decimal strings, or previews). */
export function SaleFigures({ total, paid, due, labels = ["Total", "Paid", "Due"] }: { total: string; paid: string; due: string; labels?: [string, string, string] }) {
  const items: [string, string, boolean][] = [
    [labels[0], total, false],
    [labels[1], paid, false],
    [labels[2], due, true],
  ];

  return (
    <dl className="grid grid-cols-3 gap-3 rounded-lg border bg-muted/30 p-3 text-sm">
      {items.map(([label, value, strong]) => (
        <div key={label}>
          <dt className="text-muted-foreground">{label}</dt>
          <dd className={strong ? "text-base font-semibold tabular-nums" : "tabular-nums"}>{formatAmount(value)}</dd>
        </div>
      ))}
    </dl>
  );
}
