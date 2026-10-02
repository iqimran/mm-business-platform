/**
 * Integer minor-unit helpers for restaurant screens (never floats).
 * Used for input validation and previews only; the API calculates the authoritative figures.
 */

/** Same format the API accepts: up to 12 digits and 2 decimals, no separators or exponents. */
export const AMOUNT_PATTERN = /^(0|[1-9]\d{0,11})(\.\d{1,2})?$/;

export function toMinor(decimal: string): number | null {
  const value = decimal.trim();
  if (!AMOUNT_PATTERN.test(value)) return null;
  const [whole, fraction = ""] = value.split(".");
  return Number(whole) * 100 + Number(fraction.padEnd(2, "0"));
}

export function toDecimal(minor: number): string {
  const sign = minor < 0 ? "-" : "";
  const abs = Math.abs(minor);
  return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, "0")}`;
}
