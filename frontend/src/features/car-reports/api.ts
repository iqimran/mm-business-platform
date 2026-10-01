import { apiDownload, apiRequest } from "@/lib/api-client";

export type ReportName = "cars" | "sales" | "receivables" | "payables" | "expenses" | "branches";

export type ReportParams = Record<string, string | number | undefined>;

/** Row/total values come straight from the API; money is a decimal string, null when hidden. */
export type ReportRow = Record<string, unknown>;

export type ReportResponse = {
  items: ReportRow[];
  pagination?: { current_page: number; per_page: number; total: number; last_page: number };
  totals: Record<string, unknown>;
  group?: string;
};

export function fetchReport(name: ReportName, params: ReportParams) {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== "") query.set(key, String(value));
  }

  return apiRequest<ReportResponse>(`/car-reports/${name}?${query}`);
}

export function exportReport(name: ReportName, format: "xlsx" | "pdf", params: ReportParams) {
  const query = new URLSearchParams({ format });
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== "" && key !== "page" && key !== "per_page") query.set(key, String(value));
  }

  return apiDownload(`/car-reports/${name}/export?${query}`, `car-report-${name}.${format}`);
}
