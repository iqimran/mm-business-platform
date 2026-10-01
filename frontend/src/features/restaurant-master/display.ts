import { formatAmount } from "@/lib/money";
import type { MasterRecord } from "./api";
import type { MasterField } from "./config";

/** Table cell text for a field. */
export function displayValue(field: MasterField, record: MasterRecord): string {
  if (field.type === "category") return record.category?.name ?? "—";

  const value = record[field.name];
  if (value === null || value === undefined || value === "") return "—";
  if (field.type === "money") return formatAmount(String(value));

  return String(value);
}
