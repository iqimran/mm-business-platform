/** Local calendar dates (not UTC). */
const pad = (n: number) => String(n).padStart(2, "0");
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

export type PeriodPreset = "today" | "yesterday" | "this_month" | "last_month";

export function presetRange(preset: PeriodPreset, now = new Date()): { from: string; to: string } {
  const y = now.getFullYear();
  const m = now.getMonth();
  switch (preset) {
    case "today":
      return { from: iso(now), to: iso(now) };
    case "yesterday": {
      const d = new Date(y, m, now.getDate() - 1);
      return { from: iso(d), to: iso(d) };
    }
    case "this_month":
      return { from: iso(new Date(y, m, 1)), to: iso(now) };
    case "last_month":
      return { from: iso(new Date(y, m - 1, 1)), to: iso(new Date(y, m, 0)) };
  }
}

/** Toggle sorting: same column flips direction; a new column starts descending. */
export function nextSort(current: { sort: string; direction: "asc" | "desc" }, column: string): { sort: string; direction: "asc" | "desc" } {
  if (current.sort === column) return { sort: column, direction: current.direction === "desc" ? "asc" : "desc" };
  return { sort: column, direction: "desc" };
}
