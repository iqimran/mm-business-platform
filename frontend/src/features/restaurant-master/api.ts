import { fetchAllPages } from "@/features/restaurant-common/lookups";
import { apiRequest } from "@/lib/api-client";
import type { Paginated } from "@/types/api";
import type { MasterResource } from "./config";

export type MenuCategoryRef = { id: string; name: string; is_active: boolean };

export type BranchRef = { id: string; code: string; name: string };

export type MasterRecord = {
  id: string;
  name: string;
  is_active: boolean;
  /** Menu items only. */
  category?: MenuCategoryRef;
  /** Halls only. */
  branch?: BranchRef;
  [field: string]: string | number | boolean | null | MenuCategoryRef | BranchRef | undefined;
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

/** Menu categories for dropdowns (a small catalog, all pages). */
export function fetchMenuCategories(activeOnly: boolean) {
  return fetchAllPages<MasterRecord>("/restaurant/menu-categories", activeOnly);
}
