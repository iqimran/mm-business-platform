import { beforeEach, describe, expect, it, vi } from "vitest";
import { missingMessage, missingPermissions } from "./permissions";

vi.mock("@/lib/api-client", () => ({ apiRequest: vi.fn() }));
const { apiRequest } = await import("@/lib/api-client");
const { fetchAllPages, searchCustomers, searchEventMenu } = await import("./lookups");
const mocked = vi.mocked(apiRequest);

describe("workflow permissions (UI visibility; the API enforces access)", () => {
  const can = (granted: string[]) => (p: string) => granted.includes(p);

  it("needs the lookup permissions a workflow depends on", () => {
    expect(missingPermissions(can(["restaurant.sale.create"]), "newSale")).toEqual(["restaurant.menu.view"]);
    expect(missingPermissions(can(["restaurant.sale.create", "restaurant.menu.view"]), "newSale")).toEqual([]);
    expect(missingPermissions(can(["restaurant.booking.create", "restaurant.hall.view"]), "newBooking")).toEqual(["restaurant.customer.view"]);
    expect(missingPermissions(can(["restaurant.expense.create"]), "newExpense")).toEqual(["restaurant.expense_category.view"]);
    expect(missingMessage(["restaurant.menu.view"])).toContain("Missing: restaurant.menu.view.");
  });
});

describe("lookups", () => {
  beforeEach(() => mocked.mockReset());

  const page = (items: unknown[], current: number, last: number) => ({ items, pagination: { current_page: current, per_page: 100, total: 0, last_page: last } });

  it("loads every page of a small catalog and stops at the last page", async () => {
    mocked.mockResolvedValueOnce(page([{ id: "a" }], 1, 2)).mockResolvedValueOnce(page([{ id: "b" }], 2, 2));
    await expect(fetchAllPages("/restaurant/halls", true)).resolves.toEqual([{ id: "a" }, { id: "b" }]);
    expect(mocked).toHaveBeenCalledTimes(2);
    expect(mocked.mock.calls[0][0]).toBe("/restaurant/halls?per_page=100&page=1&is_active=1");
  });

  it("is bounded to 10 pages", async () => {
    mocked.mockImplementation(async () => page([{ id: "x" }], 1, 999));
    await expect(fetchAllPages("/restaurant/expense-categories", false)).resolves.toHaveLength(10);
    expect(mocked).toHaveBeenCalledTimes(10);
    expect(mocked.mock.calls[0][0]).not.toContain("is_active");
  });

  it("searches active customers on the server", async () => {
    mocked.mockResolvedValueOnce(page([{ id: "c1", name: "Karim", phone: "+8801700000001" }], 1, 1));
    await expect(searchCustomers("kar")).resolves.toEqual([{ id: "c1", label: "Karim", hint: "+8801700000001" }]);
    expect(mocked.mock.calls[0][0]).toBe("/restaurant/customers?is_active=1&per_page=10&search=kar");
  });

  it("searches active event menu items for food packages (names only, no prices)", async () => {
    mocked.mockResolvedValueOnce(page([{ id: "e1", name: "Polao", description: "Chinigura rice" }], 1, 1));
    const options = await searchEventMenu("pol");
    expect(options).toEqual([{ id: "e1", label: "Polao", hint: "Chinigura rice" }]);
    expect(JSON.stringify(options)).not.toContain("price");
    expect(mocked.mock.calls[0][0]).toBe("/restaurant/event-menu-items?is_active=1&per_page=10&search=pol");
  });
});
