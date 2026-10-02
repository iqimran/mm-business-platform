import { describe, expect, it } from "vitest";
import { isoDate, nowLocal, today } from "./dates";
import { AMOUNT_PATTERN, toDecimal, toMinor } from "./money";
import { amountText, optionalAmountText, paymentSchema, paymentSchemaFor } from "./payments";

describe("money helpers (integer minor units)", () => {
  it("parses the API amount format without floating point", () => {
    expect(toMinor("125.5")).toBe(12550);
    expect(toMinor("0.10")).toBe(10);
    expect(toMinor(" 7 ")).toBe(700);
    for (const bad of ["1,000", "1.234", "-1", "01", "1e3", "", "abc"]) expect(toMinor(bad), bad).toBeNull();
    expect(AMOUNT_PATTERN.test("999999999999.99")).toBe(true);
    expect(AMOUNT_PATTERN.test("1000000000000")).toBe(false);
  });

  it("formats minor units", () => {
    expect(toDecimal(117850)).toBe("1178.50");
    expect(toDecimal(5)).toBe("0.05");
    expect(toDecimal(-5)).toBe("-0.05");
  });
});

describe("amount rules", () => {
  it("requires positive amounts", () => {
    const rule = amountText("Amount");
    expect(rule.safeParse("10").success).toBe(true);
    for (const bad of ["", "0", "0.00", "-1", "1.234"]) expect(rule.safeParse(bad).success, bad).toBe(false);
  });

  it("allows an empty optional amount", () => {
    expect(optionalAmountText().safeParse("").success).toBe(true);
    expect(optionalAmountText().safeParse("10.5").success).toBe(true);
    expect(optionalAmountText().safeParse("10,5").success).toBe(false);
  });
});

describe("local dates", () => {
  it("uses the local calendar, not UTC", () => {
    expect(isoDate(new Date(2026, 0, 5, 23, 59))).toBe("2026-01-05");
    expect(nowLocal().startsWith(today())).toBe(true);
  });
});

describe("payment form rules", () => {
  const payment = (o: Record<string, string> = {}) => paymentSchema.safeParse({ payment_date: today(), amount: "10", method: "cash", reference: "", notes: "", ...o });

  it("validates amount and date", () => {
    expect(payment().success).toBe(true);
    for (const amount of ["", "0", "0.00", "-1", "1.234", "abc"]) expect(payment({ amount }).success, amount).toBe(false);
    expect(payment({ payment_date: "2999-01-01" }).success).toBe(false);
    expect(payment({ method: "gold" }).success).toBe(false);
  });
});

describe("payment against a known due", () => {
  it("rejects amounts above the remaining due", () => {
    const parse = (amount: string, due = "17999.50") =>
      paymentSchemaFor(due).safeParse({ payment_date: today(), amount, method: "cash", reference: "", notes: "" });

    expect(parse("17999.50").success).toBe(true);
    expect(parse("0.01").success).toBe(true);
    const over = parse("17999.51");
    expect(over.success).toBe(false);
    expect(over.success ? null : over.error.issues[0].message).toBe("The amount exceeds the remaining due of 17999.50.");
    expect(parse("1", "0.00").success).toBe(false);
    expect(parse("0").success).toBe(false);
    expect(parse("-1").success).toBe(false);
  });
});
