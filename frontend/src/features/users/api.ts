import { apiRequest } from "@/lib/api-client";
import type { Paginated } from "@/types/api";

export type AdminUser = {
  id: string;
  name: string;
  email: string;
  is_active: boolean;
  email_verified_at: string | null;
  roles?: { id: string; name: string }[];
  branches?: { id: string; code: string; name: string }[];
  created_at: string;
};

export type UserFilters = { page: number; search: string; active: "" | "1" | "0"; roleId: string; branchId: string };

export type NewUserInput = {
  name: string;
  email: string;
  password: string;
  is_active: boolean;
  role_ids: string[];
  branch_ids: string[];
};

export type UserUpdateInput = { name?: string; email?: string; is_active?: boolean; password?: string | null };

const user = (id: string) => `/users/${encodeURIComponent(id)}`;

export function fetchUsers({ page, search, active, roleId, branchId }: UserFilters) {
  const params = new URLSearchParams({ page: String(page), per_page: "25" });
  if (search.trim()) params.set("search", search.trim());
  if (active) params.set("is_active", active);
  if (roleId) params.set("role_id", roleId);
  if (branchId) params.set("branch_id", branchId);

  return apiRequest<Paginated<AdminUser>>(`/users?${params}`);
}

export function fetchUser(id: string) {
  return apiRequest<AdminUser>(user(id));
}

export function createUser(input: NewUserInput) {
  return apiRequest<AdminUser>("/users", { method: "POST", body: input });
}

export function updateUser(id: string, input: UserUpdateInput) {
  return apiRequest<AdminUser>(user(id), { method: "PUT", body: input });
}

export function deleteUser(id: string) {
  return apiRequest<Record<string, never>>(user(id), { method: "DELETE" });
}

export function syncUserRoles(id: string, roleIds: string[]) {
  return apiRequest<AdminUser>(`${user(id)}/roles`, { method: "PUT", body: { role_ids: roleIds } });
}

export function syncUserBranches(id: string, branchIds: string[]) {
  return apiRequest<AdminUser>(`${user(id)}/branches`, { method: "PUT", body: { branch_ids: branchIds } });
}
