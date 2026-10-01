import { formatAmount } from "@/lib/money";
import type { MasterRecord } from "./api";
import type { MasterField } from "./config";

/** Table cell text for a field. */
export function displayValue(field: MasterField, record: MasterRecord): string {
  if (field.type === "category") return record.category?.name ?? "—";
  if (field.type === "branch") return record.branch ? `${record.branch.code} — ${record.branch.name}` : "—";

  const value = record[field.name];
  if (value === null || value === undefined || value === "") return "—";
  if (field.type === "money") return formatAmount(String(value));

  return String(value);
}
