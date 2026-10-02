import { describe, expect, it } from "vitest";
import { today } from "@/features/restaurant-common/dates";
import { bookingErrorFields, bookingPreview, bookingSchema, overlapping, toBookingChanges, toBookingInput, toGuests, type BookingValues } from "./schemas";

const future = "2999-06-01";
const items = [
  { id: "m1", label: "Polao" },
  { id: "m2", label: "Roast" },
];

const values = (overrides: Partial<BookingValues> = {}): BookingValues => ({
  hall_id: "h1",
  customer: { id: "c1", label: "Rahim" },
  booking_date: future,
  start_time: "18:00",
  end_time: "22:00",
  hall_charge: "100000",
  has_package: false,
  package_name: "",
  package_guests: "",
  package_price: "",
  package_items: [],
  package_notes: "",
  payment_amount: "",
  payment_method: "cash",
  payment_reference: "",
  notes: "",
  ...overrides,
});

const withPackage = (overrides: Partial<BookingValues> = {}) =>
  values({ has_package: true, package_name: "Wedding Dinner Package", package_guests: "300", package_price: "800", package_items: items, ...overrides });

const issues = (v: BookingValues, mode: "create" | "edit" = "create", originalDate?: string) => {
  const result = bookingSchema(mode, originalDate).safeParse(v);
  return result.success ? {} : Object.fromEntries(result.error.issues.map((i) => [i.path.join("."), i.message]));
};

describe("booking preview (minor units, the server recalculates)", () => {
  it("calculates 300 guests × 800 = 240,000 and adds the hall charge", () => {
    expect(bookingPreview(withPackage())).toEqual({ hall: 10000000, pkg: 24000000, total: 34000000 });
    expect(bookingPreview(withPackage({ package_guests: "150", package_price: "1250.50" })).pkg).toBe(18757500);
  });

  it("ignores the package when it is switched off or incomplete", () => {
    expect(bookingPreview(withPackage({ has_package: false })).total).toBe(10000000);
    expect(bookingPreview(withPackage({ package_guests: "abc" })).pkg).toBe(0);
  });

  it("parses guest counts", () => {
    expect(toGuests("300")).toBe(300);
    for (const bad of ["0", "-1", "1.5", "100001", "", "x"]) expect(toGuests(bad), bad).toBeNull();
  });
});

describe("booking form rules", () => {
  it("accepts a hall-only booking and a booking with a package", () => {
    expect(issues(values())).toEqual({});
    expect(issues(withPackage())).toEqual({});
  });

  it("allows a zero hall charge only with a food package", () => {
    expect(issues(withPackage({ hall_charge: "0" }))).toEqual({});
    expect(issues(values({ hall_charge: "0" })).hall_charge).toBe("Enter a hall charge or add a food package.");
    expect(issues(values({ hall_charge: "" })).hall_charge).toBeDefined();
    expect(issues(values({ hall_charge: "1,000" })).hall_charge).toBeDefined();
  });

  it("validates the package", () => {
    expect(issues(withPackage({ package_items: [] })).package_items).toBe("Select at least one event menu item.");
    for (const g of ["0", "-1", "1.5", "100001", ""]) expect(issues(withPackage({ package_guests: g })).package_guests, g).toBeDefined();
    for (const p of ["0", "0.00", "-1", "1.234", "", "abc"]) expect(issues(withPackage({ package_price: p })).package_price, p).toBeDefined();
    expect(issues(withPackage({ package_name: "" })).package_name).toBe("Give the food package a name.");
    // Package fields are ignored when the package is switched off.
    expect(issues(values({ package_guests: "0", package_items: [] }))).toEqual({});
  });

  it("limits the advance payment to the booking total (hall + package)", () => {
    expect(issues(withPackage({ payment_amount: "340000" }))).toEqual({});
    expect(issues(withPackage({ payment_amount: "340000.01" })).payment_amount).toBe("The payment exceeds the booking total of 340000.00.");
  });

  it("checks times and dates", () => {
    expect(issues(values({ start_time: "22:00", end_time: "18:00" })).end_time).toBe("The end time must be after the start time.");
    expect(issues(values({ booking_date: "2000-01-01" })).booking_date).toBe("The booking date cannot be in the past.");
    expect(issues(values({ booking_date: today() }))).toEqual({});
    expect(issues(values({ booking_date: "2000-01-01" }), "edit", "2000-01-01")).toEqual({});
    expect(issues(values({ customer: null })).customer).toBe("Select a customer.");
  });
});

describe("booking payloads", () => {
  it("sends the package as menu item ids with guests and price (never a total)", () => {
    const input = toBookingInput(withPackage({ package_notes: " 8 pm ", payment_amount: "50000" }));
    expect(input.hall_charge).toBe("100000");
    expect(input.food_package).toEqual({ name: "Wedding Dinner Package", guest_count: 300, price_per_head: "800", event_menu_item_ids: ["m1", "m2"], notes: "8 pm" });
    expect(input.food_package).not.toHaveProperty("total");
    expect(input.payment).toEqual({ amount: "50000", method: "cash", reference: null });
  });

  it("removes the package on edit when it is switched off", () => {
    expect(toBookingChanges(values()).food_package).toBeNull();
    expect(toBookingChanges(withPackage())).not.toHaveProperty("payment");
    expect(toBookingChanges(withPackage()).food_package?.guest_count).toBe(300);
  });

  it("maps API errors onto the form", () => {
    expect(
      bookingErrorFields({
        hall_charge: ["Enter a hall charge or add a food package."],
        "food_package.guest_count": ["The guest count must be between 1 and 100000."],
        "food_package.price_per_head": ["Invalid."],
        "food_package.event_menu_item_ids.1": ["This menu item is not available."],
        food_package: ["A food package needs a name, guest count, price per head and at least one menu item."],
        agreed_amount: ["Old field."],
        start_time: ["The hall is already booked 18:00–22:00 (HB-000001)."],
        customer_id: ["Select an active customer."],
        "payment.amount": ["Too much."],
        branch_id: ["Unexpected."],
      }),
    ).toEqual([
      ["hall_charge", "Enter a hall charge or add a food package."],
      ["package_guests", "The guest count must be between 1 and 100000."],
      ["package_price", "Invalid."],
      ["package_items", "This menu item is not available."],
      ["package_items", "A food package needs a name, guest count, price per head and at least one menu item."],
      ["hall_charge", "Old field."],
      ["start_time", "The hall is already booked 18:00–22:00 (HB-000001)."],
      ["customer", "Select an active customer."],
      ["payment_amount", "Too much."],
      ["root", "Unexpected."],
    ]);
  });
});

describe("availability preview", () => {
  const booked = [
    { start_time: "10:00", end_time: "12:00" },
    { start_time: "18:00", end_time: "22:00" },
  ];

  it("flags overlapping slots using [start, end) ranges", () => {
    expect(overlapping(booked, "11:00", "13:00")).toEqual([booked[0]]);
    expect(overlapping(booked, "12:00", "18:00")).toEqual([]);
    expect(overlapping(booked, "22:00", "23:00")).toEqual([]);
  });
});
