/**
 * Display formatting for API decimal strings (e.g. "700000.50" → "700,000.50").
 * Pure string manipulation: amounts are never converted to floats.
 */
export function formatAmount(decimal: string): string {
  const negative = decimal.startsWith("-");
  const [whole, fraction = "00"] = decimal.replace("-", "").split(".");
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ",");

  return `${negative ? "-" : ""}${grouped}.${fraction.padEnd(2, "0")}`;
}
