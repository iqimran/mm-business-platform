import { apiDownload, apiRequest } from "@/lib/api-client";
import type { Paginated } from "@/types/api";
import { type PaymentStatus } from "@/features/restaurant-common/payments";

export type ReportName = "sales" | "bookings" | "expenses";

/** Common report query: period, branch, grouping, sorting, page; plus report-specific filters. */
export type ReportQuery = {
  dateFrom: string;
  dateTo: string;
  branchId: string;
  groupBy: string;
  sort: string;
  direction: "asc" | "desc";
  page: number;
  extra: Record<string, string>;
};

export type ReportPage<Row, Totals> = Paginated<Row> & { totals: Totals; group_by: string | null; sort: string; direction: "asc" | "desc" };

export type SaleRow = {
  id: string;
  sale_no: string;
  sold_at: string;
  branch: { code: string; name: string };
  customer: string | null;
  items_count: number;
  total: string;
  paid: string;
  due: string;
  payment_status: PaymentStatus;
};
export type DayRow = { date: string; count: number; total: string; paid?: string; due?: string };
export type SalesTotals = { count: number; total: string; paid: string; due: string };

export type BookingRow = {
  id: string;
  booking_no: string;
  booking_date: string;
  start_time: string;
  end_time: string;
  status: "confirmed" | "completed" | "cancelled";
  branch: { code: string; name: string };
  hall: string;
  customer: string;
  hall_charge: string;
  food_package: string | null;
  food_package_name: string | null;
  food_package_guests: number | null;
  booking_total: string;
  agreed_amount: string;
  paid: string;
  due: string;
  payment_status: PaymentStatus | null;
};
export type BookingTotals = { count: number; hall_charges: string; food_packages: string; booking_total: string; agreed_amount: string; paid: string; due: string; cancelled_count: number };

export type CategoryRow = { category_id: string; category: string; count: number; total: string };
/** Expenses are full bills; paid/supplier_due show what is still owed to suppliers. */
export type ExpenseTotals = { count: number; total: string; paid: string; supplier_due: string };

export type StreamSummary = { count: number; revenue: string; received: string; outstanding_due: string; collected_in_period: string };
/** Hall booking revenue is split into hall charges and event food packages (separate from food sales). */
export type BookingStreamSummary = StreamSummary & { hall_charges: string; food_packages: string; food_package_count: number };
export type FinancialSummary = {
  period: { date_from: string; date_to: string };
  branch_id: string | null;
  food_sales: StreamSummary | null;
  hall_bookings: BookingStreamSummary | null;
  expenses: ExpenseTotals | null;
};

export function reportParams(q: ReportQuery) {
  const params = new URLSearchParams({ page: String(q.page), per_page: "25", sort: q.sort, direction: q.direction });
  if (q.dateFrom) params.set("date_from", q.dateFrom);
  if (q.dateTo) params.set("date_to", q.dateTo);
  if (q.branchId) params.set("branch_id", q.branchId);
  if (q.groupBy) params.set("group_by", q.groupBy);
  for (const [key, value] of Object.entries(q.extra)) if (value) params.set(key, value);
  return params;
}

export function fetchReport<Row, Totals>(name: ReportName, q: ReportQuery) {
  return apiRequest<ReportPage<Row, Totals>>(`/restaurant/reports/${name}?${reportParams(q)}`);
}

export function fetchSummary(dateFrom: string, dateTo: string, branchId: string) {
  const params = new URLSearchParams({ date_from: dateFrom, date_to: dateTo });
  if (branchId) params.set("branch_id", branchId);
  return apiRequest<FinancialSummary>(`/restaurant/reports/summary?${params}`);
}

export type ExportFormat = "xlsx" | "pdf";

/** Same filters and sorting as on screen; the server exports all matching rows (up to a limit). */
export function exportParams(name: ReportName | "summary", q: Pick<ReportQuery, "dateFrom" | "dateTo" | "branchId"> & Partial<ReportQuery>, format: ExportFormat) {
  const params = name === "summary" ? new URLSearchParams() : reportParams({ page: 1, groupBy: "", sort: "", direction: "desc", extra: {}, ...q } as ReportQuery);
  params.delete("page");
  params.delete("per_page");
  if (!params.get("sort")) params.delete("sort");
  if (name === "summary") {
    if (q.dateFrom) params.set("date_from", q.dateFrom);
    if (q.dateTo) params.set("date_to", q.dateTo);
    if (q.branchId) params.set("branch_id", q.branchId);
  }
  params.set("format", format);
  return params;
}

export function exportReport(name: ReportName | "summary", q: Parameters<typeof exportParams>[1], format: ExportFormat) {
  return apiDownload(`/restaurant/reports/${name}/export?${exportParams(name, q, format)}`, `restaurant-${name}.${format}`);
}
