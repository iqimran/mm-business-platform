import { describe, expect, it } from "vitest";
import { isBusinessProfileKey } from "./api";
import { businessProfileSchema, toBusinessProfileInput } from "./schemas";

const valid = { name: "MM Kitchen", address: "Plot 7, Dhanmondi, Dhaka", phone: "+880 1811-000002, 02-9876543", email: "info@mm.example" };
const errors = (v: typeof valid) => {
  const r = businessProfileSchema.safeParse(v);
  return r.success ? {} : Object.fromEntries(r.error.issues.map((i) => [i.path.join("."), i.message]));
};

describe("business profile form", () => {
  it("accepts a complete profile and an address-less one", () => {
    expect(errors(valid)).toEqual({});
    expect(errors({ ...valid, address: "", phone: "", email: "" })).toEqual({});
  });

  it("requires a name and validates contact details", () => {
    expect(errors({ ...valid, name: "  " }).name).toBe("Business name is required.");
    expect(errors({ ...valid, email: "nope" }).email).toBe("Enter a valid email address.");
    expect(errors({ ...valid, phone: "call <me>" }).phone).toBeDefined();
    expect(errors({ ...valid, name: "x".repeat(151) }).name).toBeDefined();
  });

  it("sends empty optional fields as null", () => {
    expect(toBusinessProfileInput({ name: " MM Motors ", address: " ", phone: "", email: "" })).toEqual({ name: "MM Motors", address: null, phone: null, email: null });
  });

  it("keeps business profile keys out of the generic settings list", () => {
    expect(isBusinessProfileKey("business_profile.car")).toBe(true);
    expect(isBusinessProfileKey("app.name")).toBe(false);
  });
});
