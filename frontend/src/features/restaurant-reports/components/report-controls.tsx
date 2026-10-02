"use client";

import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react";
import type { ReactNode } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { TableHead } from "@/components/ui/table";
import { useSession } from "@/features/auth/hooks";
import { formatAmount } from "@/lib/money";
import { presetRange, type PeriodPreset } from "../period";

export type Period = { dateFrom: string; dateTo: string; branchId: string };

const presets: [PeriodPreset, string][] = [
  ["today", "Today"],
  ["yesterday", "Yesterday"],
  ["this_month", "This month"],
  ["last_month", "Last month"],
];

/** Period (date range with presets) and branch filter shared by all restaurant reports. */
export function PeriodFilter({ value, onChange, children }: { value: Period; onChange: (p: Period) => void; children?: ReactNode }) {
  const { data: session } = useSession();
  const invalid = value.dateFrom && value.dateTo && value.dateFrom > value.dateTo;

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-2">
        <Input type="date" aria-label="From date" className="w-40" value={value.dateFrom} onChange={(e) => onChange({ ...value, dateFrom: e.target.value })} />
        <Input type="date" aria-label="To date" className="w-40" value={value.dateTo} min={value.dateFrom || undefined} onChange={(e) => onChange({ ...value, dateTo: e.target.value })} />
        {presets.map(([key, label]) => (
          <Button key={key} type="button" size="sm" variant="outline" onClick={() => {
            const range = presetRange(key);
            onChange({ ...value, dateFrom: range.from, dateTo: range.to });
          }}>
            {label}
          </Button>
        ))}
        {(session?.branches.length ?? 0) > 1 ? (
          <NativeSelect aria-label="Branch filter" className="w-44" value={value.branchId} onChange={(e) => onChange({ ...value, branchId: e.target.value })}>
            <option value="">All my branches</option>
            {session?.branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.code} — {b.name}
              </option>
            ))}
          </NativeSelect>
        ) : null}
        {children}
      </div>
      {invalid ? <p className="text-sm text-destructive">The start date must be on or before the end date.</p> : null}
    </div>
  );
}

/** Clickable column header for server-side sorting. */
export function SortHead({ label, column, sort, direction, onSort, align = "left" }: {
  label: string;
  column: string;
  sort: string;
  direction: "asc" | "desc";
  onSort: (column: string) => void;
  align?: "left" | "right";
}) {
  const active = sort === column;
  const Icon = !active ? ArrowUpDown : direction === "asc" ? ArrowUp : ArrowDown;

  return (
    <TableHead className={align === "right" ? "text-right" : undefined} aria-sort={active ? (direction === "asc" ? "ascending" : "descending") : "none"}>
      <button type="button" className="inline-flex items-center gap-1 hover:text-foreground" onClick={() => onSort(column)}>
        {label}
        <Icon aria-hidden className="size-3.5" />
      </button>
    </TableHead>
  );
}

/** Row of labelled totals for the whole filtered set. */
export function Totals({ items }: { items: [string, string | number][] }) {
  return (
    <dl className="grid grid-cols-2 gap-3 rounded-lg border bg-muted/30 p-3 text-sm sm:grid-cols-4">
      {items.map(([label, value]) => (
        <div key={label}>
          <dt className="text-muted-foreground">{label}</dt>
          <dd className="font-semibold tabular-nums">{typeof value === "number" ? value : formatAmount(value)}</dd>
        </div>
      ))}
    </dl>
  );
}
