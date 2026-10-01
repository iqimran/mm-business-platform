import { apiRequest } from "@/lib/api-client";

export type Role = {
  id: string;
  name: string;
  description: string | null;
  is_system: boolean;
  permissions: string[];
  users_count: number;
  created_at: string;
  updated_at: string;
};

export type Permission = {
  id: string;
  name: string;
  description: string | null;
  module: string;
};

export type RoleInput = {
  name: string;
  description: string | null;
  permissions: string[];
};

type Paginated<T> = { items: T[]; pagination: { total: number } };

export async function fetchRoles(): Promise<Role[]> {
  return (await apiRequest<Paginated<Role>>("/roles?per_page=100")).items;
}

export function fetchRole(id: string) {
  return apiRequest<Role>(`/roles/${encodeURIComponent(id)}`);
}

export function fetchPermissionCatalog() {
  return apiRequest<Permission[]>("/permissions");
}

export function createRole(input: RoleInput) {
  return apiRequest<Role>("/roles", { method: "POST", body: input });
}

export function updateRole(id: string, input: Partial<RoleInput>) {
  return apiRequest<Role>(`/roles/${encodeURIComponent(id)}`, { method: "PUT", body: input });
}

export function deleteRole(id: string) {
  return apiRequest<Record<string, never>>(`/roles/${encodeURIComponent(id)}`, { method: "DELETE" });
}
