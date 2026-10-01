import { apiRequest } from "@/lib/api-client";
import type { Paginated } from "@/types/api";

export const documentTypes = ["fitness", "tax_token", "insurance", "route_permit", "other"] as const;
export type DocumentType = (typeof documentTypes)[number];

export const documentTypeLabels: Record<DocumentType, string> = {
  fitness: "Fitness certificate",
  tax_token: "Tax token",
  insurance: "Insurance",
  route_permit: "Route permit",
  other: "Other",
};

/** Computed by the API: superseded = an older entry replaced by a renewal (never alerts). */
export type DocumentStatus = "expired" | "expiring" | "valid" | "superseded";

export type CarDocument = {
  id: string;
  type: DocumentType;
  type_label: string;
  name: string;
  custom_name: string | null;
  document_number: string | null;
  issue_date: string | null;
  expiry_date: string;
  notes: string | null;
  is_current: boolean;
  status: DocumentStatus;
  days_remaining: number;
  car?: {
    id: string;
    brand: string;
    model: string;
    chassis_number: string;
    registration_number: string | null;
    branch: { id: string; code: string; name: string } | null;
  };
  recorded_by?: { id: string; name: string } | null;
};

export type DocumentInput = {
  type: DocumentType;
  custom_name: string | null;
  document_number: string | null;
  issue_date: string | null;
  expiry_date: string;
  notes: string | null;
};

export type ExpiryFilters = {
  page: number;
  status: "alerts" | "expired" | "expiring" | "valid";
  type: "" | DocumentType;
  branchId: string;
};

const car = (id: string) => `/cars/${encodeURIComponent(id)}/documents`;

export function fetchCarDocuments(carId: string) {
  return apiRequest<{ items: CarDocument[]; alert_days: number }>(car(carId));
}

export function addCarDocument(carId: string, input: DocumentInput) {
  return apiRequest<CarDocument>(car(carId), { method: "POST", body: input });
}

export function updateCarDocument(carId: string, id: string, input: DocumentInput) {
  return apiRequest<CarDocument>(`${car(carId)}/${encodeURIComponent(id)}`, { method: "PUT", body: input });
}

export function deleteCarDocument(carId: string, id: string) {
  return apiRequest<Record<string, never>>(`${car(carId)}/${encodeURIComponent(id)}`, { method: "DELETE" });
}

export function fetchExpiringDocuments({ page, status, type, branchId }: ExpiryFilters) {
  const params = new URLSearchParams({ page: String(page), per_page: "25", status });
  if (type) params.set("type", type);
  if (branchId) params.set("branch_id", branchId);

  return apiRequest<Paginated<CarDocument>>(`/car-documents/expiring?${params}`);
}

export function fetchExpirySummary() {
  return apiRequest<{ expired: number; expiring: number; alert_days: number }>("/car-documents/expiry-summary");
}
