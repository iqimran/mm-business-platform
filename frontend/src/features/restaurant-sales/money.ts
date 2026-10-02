/**
 * Sale line previews (integer minor units). The API recalculates and stores the authoritative figures.
 */
import { toMinor } from "@/features/restaurant-common/money";

/** Quantity as a whole number 1..9999, otherwise null. */
export function toQuantity(value: string): number | null {
  const v = value.trim();
  if (!/^\d{1,4}$/.test(v)) return null;
  const n = Number(v);
  return n >= 1 ? n : null;
}

/** Line total preview = quantity × unit price; null while the quantity is invalid. */
export function lineTotalMinor(quantity: string, unitPrice: string): number | null {
  const q = toQuantity(quantity);
  const p = toMinor(unitPrice);
  return q === null || p === null ? null : q * p;
}

export function saleTotalMinor(lines: { quantity: string; unit_price: string }[]): number {
  return lines.reduce((sum, line) => sum + (lineTotalMinor(line.quantity, line.unit_price) ?? 0), 0);
}
