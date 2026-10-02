import { describe, expect, it } from "vitest";
import { reportParams } from "./api";
import { nextSort, presetRange } from "./period";

describe("period presets", () => {
  const now = new Date(2026, 9, 2, 15, 30); // 2 Oct 2026, local time

  it("computes local calendar ranges", () => {
    expect(presetRange("today", now)).toEqual({ from: "2026-10-02", to: "2026-10-02" });
    expect(presetRange("yesterday", now)).toEqual({ from: "2026-10-01", to: "2026-10-01" });
    expect(presetRange("this_month", now)).toEqual({ from: "2026-10-01", to: "2026-10-02" });
    expect(presetRange("last_month", now)).toEqual({ from: "2026-09-01", to: "2026-09-30" });
  });

  it("handles month and year boundaries", () => {
    expect(presetRange("yesterday", new Date(2026, 0, 1))).toEqual({ from: "2025-12-31", to: "2025-12-31" });
    expect(presetRange("last_month", new Date(2026, 0, 15))).toEqual({ from: "2025-12-01", to: "2025-12-31" });
    expect(presetRange("last_month", new Date(2024, 2, 10))).toEqual({ from: "2024-02-01", to: "2024-02-29" });
  });
});

describe("sorting", () => {
  it("flips the direction on the same column and starts a new column descending", () => {
    expect(nextSort({ sort: "total", direction: "desc" }, "total")).toEqual({ sort: "total", direction: "asc" });
    expect(nextSort({ sort: "total", direction: "asc" }, "total")).toEqual({ sort: "total", direction: "desc" });
    expect(nextSort({ sort: "total", direction: "asc" }, "due")).toEqual({ sort: "due", direction: "desc" });
  });
});

describe("report query", () => {
  it("sends only the filters that are set (server-side filtering)", () => {
    const params = reportParams({
      dateFrom: "2026-10-01", dateTo: "2026-10-02", branchId: "", groupBy: "day", sort: "day", direction: "desc", page: 2,
      extra: { payment_status: "partial", category_id: "" },
    });
    expect(Object.fromEntries(params)).toEqual({
      page: "2", per_page: "25", sort: "day", direction: "desc", date_from: "2026-10-01", date_to: "2026-10-02", group_by: "day", payment_status: "partial",
    });
  });
});
