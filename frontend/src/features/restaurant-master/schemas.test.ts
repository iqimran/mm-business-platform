import { describe, expect, it } from "vitest";
import type { MasterRecord } from "./api";
import { customerResource, menuCategoryResource, menuItemResource, supplierResource } from "./config";
import { displayValue } from "./display";
import { buildSchema, toPayload } from "./schemas";

const item = (values: Record<string, string | boolean>) =>
  buildSchema(menuItemResource).safeParse({ is_active: true, name: "Tea", category_id: "01J", price: "20", description: "", ...values });

const errorsOf = (result: ReturnType<typeof item>) => (result.success ? {} : result.error.flatten().fieldErrors);

describe("menu item schema", () => {
  it("accepts valid decimal prices", () => {
    for (const price of ["20", "20.5", "350.50", "0.01", "999999999999.99"]) expect(item({ price }).success).toBe(true);
  });

  it("rejects zero, negative, malformed and over-precise prices", () => {
    for (const price of ["", "0", "0.00", "-5", "1.234", "abc", "1,000", "01", "1000000000000"]) {
      expect(errorsOf(item({ price })).price, price).toBeDefined();
    }
    expect(errorsOf(item({ price: "0" })).price).toContain("Selling price must be greater than zero.");
  });

  it("requires a name and a category", () => {
    const errors = errorsOf(item({ name: "  ", category_id: "" }));
    expect(errors.name).toContain("Name is required.");
    expect(errors.category_id).toContain("Select a category.");
  });
});

describe("other master-data schemas", () => {
  it("validates customer name length and requirement", () => {
    const schema = buildSchema(customerResource);
    expect(schema.safeParse({ is_active: true, name: "Karim", phone: "", address: "", notes: "" }).success).toBe(true);
    expect(schema.safeParse({ is_active: true, name: "", phone: "", address: "", notes: "" }).success).toBe(false);
    expect(schema.safeParse({ is_active: true, name: "a".repeat(151), phone: "", address: "", notes: "" }).success).toBe(false);
  });

  it("requires a supplier name", () => {
    const base = { is_active: true, contact_person: "", phone: "", address: "", notes: "" };
    expect(buildSchema(supplierResource).safeParse({ ...base, name: " " }).success).toBe(false);
  });

  it("limits category display order to 0..9999 whole numbers", () => {
    const parse = (sort_order: string) => buildSchema(menuCategoryResource).safeParse({ is_active: true, name: "Drinks", description: "", sort_order });
    for (const ok of ["", "0", "12", "9999"]) expect(parse(ok).success, ok).toBe(true);
    for (const bad of ["-1", "1.5", "10000", "x"]) expect(parse(bad).success, bad).toBe(false);
  });
});

describe("toPayload", () => {
  it("sends empty optional text as null and keeps prices as strings", () => {
    expect(toPayload(menuItemResource, { name: " Tea ", category_id: "01J", price: "20.50", description: "", is_active: false })).toEqual({
      name: "Tea",
      category_id: "01J",
      price: "20.50",
      description: null,
      is_active: false,
    });
  });

  it("converts display order to an integer and omits it when empty", () => {
    expect(toPayload(menuCategoryResource, { name: "Drinks", description: "", sort_order: "5", is_active: true })).toMatchObject({ sort_order: 5 });
    expect(toPayload(menuCategoryResource, { name: "Drinks", description: "", sort_order: "", is_active: true })).not.toHaveProperty("sort_order");
  });
});

describe("displayValue", () => {
  const record: MasterRecord = { id: "1", name: "Tea", is_active: true, price: "1500.5", description: null, category: { id: "c", name: "Drinks", is_active: false } };
  const field = (name: string) => menuItemResource.fields.find((f) => f.name === name)!;

  it("formats prices without floating point and shows category names", () => {
    expect(displayValue(field("price"), record)).toBe("1,500.50");
    expect(displayValue(field("category_id"), record)).toBe("Drinks");
    expect(displayValue(field("description"), record)).toBe("—");
  });
});

describe("halls (branch-scoped)", () => {
  it("requires a branch and a positive capacity, and can clear the capacity", async () => {
    const { hallResource } = await import("./config");
    const parse = (v: Record<string, string>) => buildSchema(hallResource).safeParse({ is_active: true, name: "Grand", branch_id: "b1", capacity: "", description: "", ...v });
    expect(parse({}).success).toBe(true);
    expect(parse({ branch_id: "" }).success).toBe(false);
    expect(parse({ capacity: "0" }).success).toBe(false);
    expect(parse({ capacity: "300" }).success).toBe(true);
    expect(toPayload(hallResource, { name: "Grand", branch_id: "b1", capacity: "", description: "", is_active: true })).toMatchObject({ capacity: null });
    expect(toPayload(hallResource, { name: "Grand", branch_id: "b1", capacity: "300", description: "", is_active: true })).toMatchObject({ capacity: 300 });
  });
});
