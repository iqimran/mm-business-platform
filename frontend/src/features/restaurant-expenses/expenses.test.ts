import { describe, expect, it } from "vitest";
import { duePreview, expenseSchema, rangeDays, toExpenseInput, type ExpenseValues } from "./schemas";
import { today } from "@/features/restaurant-common/dates";

const values = (overrides: Partial<ExpenseValues> = {}): ExpenseValues => ({
  branch_id: "b1",
  category_id: "c1",
  supplier: null,
  expense_date: today(),
  amount: "10000",
  description: "",
  reference: "",
  paid_amount: "",
  payment_method: "cash",
  payment_reference: "",
  ...overrides,
});

const issues = (v: ExpenseValues) => {
  const result = expenseSchema.safeParse(v);
  return result.success ? {} : Object.fromEntries(result.error.issues.map((i) => [i.path.join("."), i.message]));
};

describe("expense form rules", () => {
  it("accepts a valid expense", () => {
    expect(issues(values())).toEqual({});
    expect(issues(values({ amount: "0.01" }))).toEqual({});
  });

  it("rejects zero, negative and malformed amounts", () => {
    for (const amount of ["", "0", "0.00", "-5", "1.234", "1,000", "abc"]) expect(issues(values({ amount })).amount, amount).toBeDefined();
    expect(issues(values({ amount: "0" })).amount).toBe("Amount must be greater than zero.");
  });

  it("requires a category, a branch and a date that is not in the future", () => {
    expect(issues(values({ category_id: "" })).category_id).toBe("Select a category.");
    expect(issues(values({ branch_id: "" })).branch_id).toBe("Select a branch.");
    expect(issues(values({ expense_date: "2999-01-01" })).expense_date).toBe("The expense date cannot be in the future.");
  });
});

describe("toExpenseInput", () => {
  it("trims text, maps the supplier and sends empty text as null", () => {
    expect(toExpenseInput(values({ supplier: { id: "s1", label: "Fresh" }, description: " Fish ", reference: "", amount: " 500.5 " }))).toEqual({
      branch_id: "b1",
      category_id: "c1",
      supplier_id: "s1",
      expense_date: today(),
      amount: "500.5",
      description: "Fish",
      reference: null,
      paid_amount: null,
      payment_method: "cash",
      payment_reference: null,
    });
    expect(toExpenseInput(values()).supplier_id).toBeNull();
  });

  it("sends the amount paid now only for supplier bills (empty = full amount)", () => {
    const supplier = { id: "s1", label: "Fresh" };
    expect(toExpenseInput(values({ supplier, paid_amount: "4000", payment_reference: " BK-1 " }))).toMatchObject({ paid_amount: "4000", payment_reference: "BK-1" });
    expect(toExpenseInput(values({ supplier, paid_amount: "0" })).paid_amount).toBe("0");
    expect(toExpenseInput(values({ supplier })).paid_amount).toBeNull();
    expect(toExpenseInput(values({ paid_amount: "4000" })).paid_amount).toBeNull();
  });
});

describe("supplier dues on the expense form", () => {
  const supplier = { id: "s1", label: "Fresh" };

  it("allows partial or no payment for supplier bills", () => {
    expect(issues(values({ supplier, paid_amount: "4000" }))).toEqual({});
    expect(issues(values({ supplier, paid_amount: "0" }))).toEqual({});
    expect(issues(values({ supplier, paid_amount: "10000.01" })).paid_amount).toBe("The amount paid cannot exceed the expense amount.");
    expect(issues(values({ supplier, paid_amount: "1,000" })).paid_amount).toBeDefined();
  });

  it("requires a supplier for anything not fully paid", () => {
    expect(issues(values({ paid_amount: "4000" })).paid_amount).toBe("Select a supplier for an expense that is not fully paid.");
    expect(issues(values({ paid_amount: "10000" }))).toEqual({});
  });

  it("previews the due without floating point", () => {
    expect(duePreview("10000", "4000")).toBe("6000.00");
    expect(duePreview("10000", "")).toBe("0.00");
    expect(duePreview("0.30", "0.10")).toBe("0.20");
    expect(duePreview("abc", "1")).toBeNull();
  });
});

describe("rangeDays", () => {
  it("counts days inclusively and rejects inverted or invalid ranges", () => {
    expect(rangeDays("2026-10-02", "2026-10-02")).toBe(1);
    expect(rangeDays("2026-10-01", "2026-10-31")).toBe(31);
    expect(rangeDays("2025-10-02", "2026-10-02")).toBe(366);
    expect(rangeDays("2026-10-03", "2026-10-02")).toBeNull();
    expect(rangeDays("", "2026-10-02")).toBeNull();
  });
});
