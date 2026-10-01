import { apiRequest } from "@/lib/api-client";
import type { Paginated } from "@/types/api";

export type Branch = {
  id: string;
  code: string;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  is_active: boolean;
  users_count?: number;
  created_at: string;
};

export type BranchInput = {
  code: string;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  is_active: boolean;
};

export type BranchFilters = { page: number; search: string; active: "" | "1" | "0" };

export function fetchBranches({ page, search, active }: BranchFilters) {
  const params = new URLSearchParams({ page: String(page), per_page: "25" });
  if (search.trim()) params.set("search", search.trim());
  if (active) params.set("is_active", active);

  return apiRequest<Paginated<Branch>>(`/branches?${params}`);
}

/** All branches the user can access (for pickers). */
export async function fetchBranchOptions() {
  return (await apiRequest<Paginated<Branch>>("/branches?per_page=100")).items;
}

export function createBranch(input: BranchInput) {
  return apiRequest<Branch>("/branches", { method: "POST", body: input });
}

export function updateBranch(id: string, input: Partial<BranchInput>) {
  return apiRequest<Branch>(`/branches/${encodeURIComponent(id)}`, { method: "PUT", body: input });
}
