import { describe, expect, it } from "vitest";
import { bookingErrorFields, bookingSchema, overlapping, toBookingChanges, toBookingInput, type BookingValues } from "./schemas";
import { today } from "@/features/restaurant-common/dates";

const future = "2999-06-01";

const values = (overrides: Partial<BookingValues> = {}): BookingValues => ({
  hall_id: "h1",
  customer: { id: "c1", label: "Rahim" },
  booking_date: future,
  start_time: "18:00",
  end_time: "22:00",
  agreed_amount: "50000",
  payment_amount: "",
  payment_method: "cash",
  payment_reference: "",
  notes: "",
  ...overrides,
});

const issues = (v: BookingValues, mode: "create" | "edit" = "create", originalDate?: string) => {
  const result = bookingSchema(mode, originalDate).safeParse(v);
  return result.success ? {} : Object.fromEntries(result.error.issues.map((i) => [i.path.join("."), i.message]));
};

describe("booking form rules", () => {
  it("accepts a valid unpaid booking", () => {
    expect(issues(values())).toEqual({});
  });

  it("requires hall, customer, date and a positive agreed amount", () => {
    expect(issues(values({ hall_id: "" })).hall_id).toBe("Select a hall.");
    expect(issues(values({ customer: null })).customer).toBe("Select a customer.");
    for (const amount of ["", "0", "-1", "1,000", "1.234"]) expect(issues(values({ agreed_amount: amount })).agreed_amount, amount).toBeDefined();
  });

  it("requires the end time after the start time", () => {
    expect(issues(values({ start_time: "22:00", end_time: "18:00" })).end_time).toBe("The end time must be after the start time.");
    expect(issues(values({ start_time: "18:00", end_time: "18:00" })).end_time).toBeDefined();
    expect(issues(values({ start_time: "" })).start_time).toBe("Enter a start time.");
  });

  it("rejects past dates, except an existing booking keeping its date", () => {
    expect(issues(values({ booking_date: "2000-01-01" })).booking_date).toBe("The booking date cannot be in the past.");
    expect(issues(values({ booking_date: today() }))).toEqual({});
    expect(issues(values({ booking_date: "2000-01-01" }), "edit", "2000-01-01")).toEqual({});
    expect(issues(values({ booking_date: "2000-01-02" }), "edit", "2000-01-01").booking_date).toBeDefined();
  });

  it("rejects an advance payment above the agreed amount", () => {
    expect(issues(values({ payment_amount: "50000.01" })).payment_amount).toBe("The payment exceeds the agreed amount of 50000.00.");
    expect(issues(values({ payment_amount: "50000" }))).toEqual({});
  });
});

describe("booking payloads", () => {
  it("sends the advance payment only when one is entered", () => {
    expect(toBookingInput(values()).payment).toBeNull();
    expect(toBookingInput(values({ payment_amount: "0" })).payment).toBeNull();
    expect(toBookingInput(values({ payment_amount: " 5000 ", payment_reference: " TX " })).payment).toEqual({ amount: "5000", method: "cash", reference: "TX" });
    expect(toBookingInput(values()).customer_id).toBe("c1");
  });

  it("edits never include payments", () => {
    const changes = toBookingChanges(values({ payment_amount: "5000", notes: " VIP " }));
    expect(changes).not.toHaveProperty("payment");
    expect(changes.notes).toBe("VIP");
  });

  it("maps API errors onto the form", () => {
    expect(
      bookingErrorFields({
        start_time: ["The hall is already booked 18:00–22:00 (HB-000001)."],
        customer_id: ["Select an active customer."],
        "payment.amount": ["The payment exceeds the agreed amount of 100.00."],
        agreed_amount: ["The agreed amount cannot be less than the amount already paid (200.00)."],
        branch_id: ["Unexpected."],
      }),
    ).toEqual([
      ["start_time", "The hall is already booked 18:00–22:00 (HB-000001)."],
      ["customer", "Select an active customer."],
      ["payment_amount", "The payment exceeds the agreed amount of 100.00."],
      ["agreed_amount", "The agreed amount cannot be less than the amount already paid (200.00)."],
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
    expect(overlapping(booked, "09:00", "23:00")).toEqual(booked);
    expect(overlapping(booked, "12:00", "18:00")).toEqual([]);
    expect(overlapping(booked, "22:00", "23:00")).toEqual([]);
    expect(overlapping(booked, "20:00", "19:00")).toEqual([]);
  });
});
