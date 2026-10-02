import { apiRequest } from "@/lib/api-client";
import type { Paginated } from "@/types/api";

/**
 * Lookups of restaurant master data used by several screens.
 * Large lists use server search (a few matches); small catalogs load all pages (bounded).
 */
export type PickerOption = { id: string; label: string; hint?: string };

export async function searchCustomers(term: string): Promise<PickerOption[]> {
  const params = new URLSearchParams({ is_active: "1", per_page: "10" });
  if (term) params.set("search", term);
  const page = await apiRequest<Paginated<{ id: string; name: string; phone: string | null }>>(`/restaurant/customers?${params}`);

  return page.items.map((c) => ({ id: c.id, label: c.name, hint: c.phone ?? undefined }));
}

export async function searchSuppliers(term: string): Promise<PickerOption[]> {
  const params = new URLSearchParams({ is_active: "1", per_page: "10" });
  if (term) params.set("search", term);
  const page = await apiRequest<Paginated<{ id: string; name: string; contact_person: string | null; phone: string | null }>>(`/restaurant/suppliers?${params}`);

  return page.items.map((s) => ({ id: s.id, label: s.name, hint: [s.contact_person, s.phone].filter(Boolean).join(" · ") || undefined }));
}

/**
 * Every record of a small catalog (categories, halls) for dropdowns, so nothing is silently cut off.
 * Bounded to 1,000 records as a safety net; large lists must use a searchable picker instead.
 */
export async function fetchAllPages<T>(path: string, activeOnly: boolean): Promise<T[]> {
  const all: T[] = [];
  for (let page = 1; page <= 10; page++) {
    const params = new URLSearchParams({ per_page: "100", page: String(page) });
    if (activeOnly) params.set("is_active", "1");
    const result = await apiRequest<Paginated<T>>(`${path}?${params}`);
    all.push(...result.items);
    if (page >= result.pagination.last_page) break;
  }
  return all;
}
