/**
 * Integer minor-unit arithmetic for on-screen previews (never floats).
 * The API recalculates and stores the authoritative figures.
 */
const AMOUNT = /^(0|[1-9]\d{0,11})(\.\d{1,2})?$/;

export function toMinor(decimal: string): number | null {
  const value = decimal.trim();
  if (!AMOUNT.test(value)) return null;
  const [whole, fraction = ""] = value.split(".");
  return Number(whole) * 100 + Number(fraction.padEnd(2, "0"));
}

export function toDecimal(minor: number): string {
  const sign = minor < 0 ? "-" : "";
  const abs = Math.abs(minor);
  return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, "0")}`;
}

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
