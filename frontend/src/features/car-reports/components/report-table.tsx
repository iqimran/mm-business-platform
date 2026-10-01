"use client";

import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react";
import type { ReactNode } from "react";
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { formatAmount } from "@/lib/money";
import type { ReportRow } from "../api";

export type Column = {
  key: string;
  label: string;
  /** Server-side sort key; omit for non-sortable columns. */
  sort?: string;
  money?: boolean;
  align?: "right";
  /** Custom cell; defaults to the value at `key` (dot paths allowed). */
  render?: (row: ReportRow) => ReactNode;
  /** Key in `totals` to show in the footer. */
  total?: string;
  hideBelow?: "sm" | "md" | "lg";
};

export type SortState = { sort: string; direction: "asc" | "desc" };

export function valueAt(row: ReportRow, path: string): unknown {
  return path.split(".").reduce<unknown>((v, k) => (v && typeof v === "object" ? (v as Record<string, unknown>)[k] : undefined), row);
}

function display(value: unknown, money?: boolean): ReactNode {
  if (value === null || value === undefined || value === "") return <span className="text-muted-foreground">—</span>;
  if (money && typeof value === "string") {
    return <span className={value.startsWith("-") ? "text-destructive" : undefined}>{formatAmount(value)}</span>;
  }
  return String(value);
}

const hide = { sm: "hidden sm:table-cell", md: "hidden md:table-cell", lg: "hidden lg:table-cell" };

/**
 * Generic report table: sorting is requested from the server (never done client-side),
 * totals come from the API's totals block for the whole filtered set.
 */
export function ReportTable({
  columns,
  rows,
  totals,
  sort,
  onSort,
  empty = "No records match these filters.",
}: {
  columns: Column[];
  rows: ReportRow[];
  totals?: Record<string, unknown>;
  sort?: SortState;
  onSort?: (s: SortState) => void;
  empty?: string;
}) {
  const visible = columns.filter((c) => c.money !== true || rows.some((r) => valueAt(r, c.key) !== null) || (c.total && totals?.[c.total] != null));
  const hasTotals = visible.some((c) => c.total && totals && totals[c.total] !== undefined);

  return (
    <div className="rounded-lg border">
      <Table>
        <TableHeader>
          <TableRow>
            {visible.map((c) => {
              const active = sort?.sort === c.sort;
              const Icon = !active ? ArrowUpDown : sort?.direction === "asc" ? ArrowUp : ArrowDown;
              return (
                <TableHead
                  key={c.key}
                  className={`${c.align === "right" || c.money ? "text-right" : ""} ${c.hideBelow ? hide[c.hideBelow] : ""}`}
                  aria-sort={active ? (sort?.direction === "asc" ? "ascending" : "descending") : undefined}
                >
                  {c.sort && onSort ? (
                    <button
                      type="button"
                      className="inline-flex items-center gap-1 hover:text-foreground"
                      onClick={() => onSort({ sort: c.sort!, direction: active && sort?.direction === "desc" ? "asc" : "desc" })}
                    >
                      {c.label}
                      <Icon className={`size-3.5 ${active ? "" : "opacity-40"}`} aria-hidden />
                    </button>
                  ) : (
                    c.label
                  )}
                </TableHead>
              );
            })}
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.length === 0 ? (
            <TableRow>
              <TableCell colSpan={visible.length} className="py-8 text-center text-muted-foreground">
                {empty}
              </TableCell>
            </TableRow>
          ) : null}
          {rows.map((row, i) => (
            <TableRow key={i}>
              {visible.map((c) => (
                <TableCell
                  key={c.key}
                  className={`${c.align === "right" || c.money ? "text-right tabular-nums" : ""} ${c.hideBelow ? hide[c.hideBelow] : ""}`}
                >
                  {c.render ? c.render(row) : display(valueAt(row, c.key), c.money)}
                </TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
        {hasTotals && rows.length > 0 ? (
          <TableFooter>
            <TableRow>
              {visible.map((c, i) => (
                <TableCell
                  key={c.key}
                  className={`font-semibold ${c.align === "right" || c.money ? "text-right tabular-nums" : ""} ${c.hideBelow ? hide[c.hideBelow] : ""}`}
                >
                  {c.total && totals ? display(totals[c.total], c.money) : i === 0 ? "Total (all pages)" : null}
                </TableCell>
              ))}
            </TableRow>
          </TableFooter>
        ) : null}
      </Table>
    </div>
  );
}
