import { apiRequest } from "@/lib/api-client";
import type { Paginated } from "@/types/api";
import type { MasterResource } from "./config";

export type MenuCategoryRef = { id: string; name: string; is_active: boolean };

export type MasterRecord = {
  id: string;
  name: string;
  is_active: boolean;
  /** Menu items only. */
  category?: MenuCategoryRef;
  [field: string]: string | number | boolean | null | MenuCategoryRef | undefined;
};

export type MasterFilters = {
  page: number;
  search: string;
  /** "" = all */
  active: "" | "1" | "0";
  /** Menu items: "" = all categories. */
  categoryId: string;
};

export function fetchRecords(resource: MasterResource, { page, search, active, categoryId }: MasterFilters) {
  const params = new URLSearchParams({ page: String(page), per_page: "25" });
  if (search.trim()) params.set("search", search.trim());
  if (active) params.set("is_active", active);
  if (categoryId) params.set("category_id", categoryId);

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

/**
 * Menu categories for dropdowns (a small catalog): loads every page so nothing is silently cut off.
 * Bounded to 1,000 records as a safety net.
 */
export async function fetchMenuCategories(activeOnly: boolean) {
  const all: MasterRecord[] = [];
  for (let page = 1; page <= 10; page++) {
    const params = new URLSearchParams({ per_page: "100", page: String(page) });
    if (activeOnly) params.set("is_active", "1");
    const result = await apiRequest<Paginated<MasterRecord>>(`/restaurant/menu-categories?${params}`);
    all.push(...result.items);
    if (page >= result.pagination.last_page) break;
  }
  return all;
}
