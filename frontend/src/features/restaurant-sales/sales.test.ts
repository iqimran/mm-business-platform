import { describe, expect, it } from "vitest";
import { lineTotalMinor, saleTotalMinor, toQuantity } from "./money";
import { saleErrorFields, saleSchema, toSaleInput, type SaleValues } from "./schemas";
import { toDecimal, toMinor } from "@/features/restaurant-common/money";

const line = (quantity: string, unit_price: string, id = "m1") => ({ menu_item_id: id, name: "Dish", unit_price, quantity });

const sale = (overrides: Partial<SaleValues> = {}): SaleValues => ({
  branch_id: "b1",
  customer: { id: "c1", label: "Karim" },
  sold_at: "2026-01-01T10:00",
  items: [line("1", "350.00")],
  payment_amount: "",
  payment_method: "cash",
  payment_reference: "",
  notes: "",
  ...overrides,
});

const issues = (values: SaleValues) => {
  const result = saleSchema.safeParse(values);
  return result.success ? {} : Object.fromEntries(result.error.issues.map((i) => [i.path.join("."), i.message]));
};

describe("money preview (integer minor units)", () => {
  it("parses and formats decimals without floating point", () => {
    expect(toMinor("125.5")).toBe(12550);
    expect(toMinor("0.1")).toBe(10);
    expect(toMinor("1,000")).toBeNull();
    expect(toMinor("1.234")).toBeNull();
    expect(toDecimal(117850)).toBe("1178.50");
    expect(toDecimal(-5)).toBe("-0.05");
  });

  it("computes line totals and the sale total", () => {
    expect(lineTotalMinor("3", "125.50")).toBe(37650);
    expect(lineTotalMinor("0", "125.50")).toBeNull();
    expect(lineTotalMinor("1.5", "125.50")).toBeNull();
    // 0.1 + 0.2 style float errors cannot happen.
    expect(saleTotalMinor([line("1", "0.10"), line("1", "0.20")])).toBe(30);
    expect(saleTotalMinor([line("3", "125.50"), line("4", "25.50"), line("2", "350")])).toBe(117850);
    expect(saleTotalMinor([line("x", "125.50"), line("2", "1")])).toBe(200);
  });

  it("accepts whole quantities from 1 to 9999 only", () => {
    expect(toQuantity("1")).toBe(1);
    expect(toQuantity("9999")).toBe(9999);
    for (const bad of ["0", "10000", "-1", "1.5", "", "abc"]) expect(toQuantity(bad), bad).toBeNull();
  });
});

describe("sale form rules", () => {
  it("accepts an unpaid sale with a customer", () => {
    expect(issues(sale())).toEqual({});
  });

  it("requires items, branch and valid quantities", () => {
    expect(issues(sale({ items: [] })).items).toBe("Add at least one menu item.");
    expect(issues(sale({ branch_id: "" })).branch_id).toBe("Select a branch.");
    expect(issues(sale({ items: [line("0", "10")] }))["items.0.quantity"]).toBeDefined();
  });

  it("rejects a payment above the total", () => {
    expect(issues(sale({ payment_amount: "350.01" })).payment_amount).toBe("The payment exceeds the sale total of 350.00.");
    expect(issues(sale({ payment_amount: "1,000" })).payment_amount).toBeDefined();
    expect(issues(sale({ payment_amount: "350" }))).toEqual({});
  });

  it("allows walk-in sales only when fully paid", () => {
    expect(issues(sale({ customer: null })).customer).toBeDefined();
    expect(issues(sale({ customer: null, payment_amount: "100" })).customer).toBeDefined();
    expect(issues(sale({ customer: null, payment_amount: "350.00" }))).toEqual({});
  });

  it("rejects a future sale time", () => {
    expect(issues(sale({ sold_at: "2999-01-01T00:00" })).sold_at).toBe("The sale time cannot be in the future.");
  });
});

describe("toSaleInput", () => {
  it("sends menu items and quantities only (prices come from the server)", () => {
    const input = toSaleInput(sale({ items: [line("3", "125.50", "a"), line("2", "1", "b")], payment_amount: "100", payment_reference: " TX9 " }));
    expect(input.items).toEqual([
      { menu_item_id: "a", quantity: 3 },
      { menu_item_id: "b", quantity: 2 },
    ]);
    expect(input.items[0]).not.toHaveProperty("unit_price");
    expect(input.sold_at).toBe("2026-01-01 10:00");
    expect(input.payment).toEqual({ amount: "100", method: "cash", reference: "TX9" });
  });

  it("omits the payment for unpaid sales and maps walk-in to a null customer", () => {
    expect(toSaleInput(sale()).payment).toBeNull();
    expect(toSaleInput(sale({ payment_amount: "0.00" })).payment).toBeNull();
    expect(toSaleInput(sale({ customer: null })).customer_id).toBeNull();
  });
});

describe("saleErrorFields", () => {
  it("maps API errors onto form fields and line quantities", () => {
    expect(
      saleErrorFields({
        "items.2.menu_item_id": ["This menu item is not available."],
        "items.0.quantity": ["The line total is too large."],
        "payment.amount": ["The payment exceeds the sale total of 50.00."],
        "payment.method": ["Invalid."],
        customer_id: ["Select an active customer."],
        branch_id: ["The selected branch is invalid."],
        something_else: ["Oops."],
      }),
    ).toEqual([
      ["items.2.quantity", "This menu item is not available."],
      ["items.0.quantity", "The line total is too large."],
      ["payment_amount", "The payment exceeds the sale total of 50.00."],
      ["payment_method", "Invalid."],
      ["customer", "Select an active customer."],
      ["branch_id", "The selected branch is invalid."],
      ["root", "Oops."],
    ]);
  });
});
