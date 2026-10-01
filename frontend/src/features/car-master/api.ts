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

/** Server-side search for pickers: a few active matches, never the whole table. */
export async function searchActive(resource: MasterResource, term: string) {
  const params = new URLSearchParams({ is_active: "1", per_page: "10" });
  if (term) params.set("search", term);

  return (await apiRequest<Paginated<MasterRecord>>(`/${resource.path}?${params}`)).items.map((r) => ({
    id: r.id,
    label: String(r.name),
    hint: [r.phone, r.national_id].filter(Boolean).join(" · ") || undefined,
  }));
}

/**
 * Small catalogs (e.g. expense types) for dropdowns: loads every page, so nothing is silently cut off.
 * Bounded to 1,000 records as a safety net; large lists must use a searchable picker instead.
 */
export async function fetchAllActive(resource: MasterResource) {
  const all: MasterRecord[] = [];
  for (let page = 1; page <= 10; page++) {
    const result = await apiRequest<Paginated<MasterRecord>>(`/${resource.path}?is_active=1&per_page=100&page=${page}`);
    all.push(...result.items);
    if (page >= result.pagination.last_page) break;
  }
  return all;
}
