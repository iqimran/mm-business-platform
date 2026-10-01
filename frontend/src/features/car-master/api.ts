import { apiRequest } from "@/lib/api-client";
import type { Paginated } from "@/types/api";
import type { MasterResource } from "./config";

export type MasterRecord = {
  id: string;
  name: string;
  is_active: boolean;
  [field: string]: string | number | boolean | null;
};

export type MasterFilters = {
  page: number;
  search: string;
  /** "" = all */
  active: "" | "1" | "0";
};

export function fetchRecords(resource: MasterResource, { page, search, active }: MasterFilters) {
  const params = new URLSearchParams({ page: String(page), per_page: "25" });
  if (search.trim()) params.set("search", search.trim());
  if (active) params.set("is_active", active);

  return apiRequest<Paginated<MasterRecord>>(`/${resource.path}?${params}`);
}

export function createRecord(resource: MasterResource, input: Record<string, unknown>) {
  return apiRequest<MasterRecord>(`/${resource.path}`, { method: "POST", body: input });
}

export function updateRecord(resource: MasterResource, id: string, input: Record<string, unknown>) {
  return apiRequest<MasterRecord>(`/${resource.path}/${encodeURIComponent(id)}`, { method: "PUT", body: input });
}

export function deleteRecord(resource: MasterResource, id: string) {
  return apiRequest<Record<string, never>>(`/${resource.path}/${encodeURIComponent(id)}`, { method: "DELETE" });
}

/** Active records for pickers (e.g. dealer selection on the car form). */
export async function fetchActiveOptions(resource: MasterResource) {
  return (await apiRequest<Paginated<MasterRecord>>(`/${resource.path}?is_active=1&per_page=100`)).items;
}
