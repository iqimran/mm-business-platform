"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { sessionQueryKey } from "@/features/auth/hooks";
import { createRole, deleteRole, fetchPermissionCatalog, fetchRole, fetchRoles, updateRole, type RoleInput } from "./api";

const rolesKey = ["roles"] as const;

export function useRoles() {
  return useQuery({ queryKey: rolesKey, queryFn: fetchRoles });
}

export function useRole(id: string) {
  return useQuery({ queryKey: [...rolesKey, id], queryFn: () => fetchRole(id) });
}

export function usePermissionCatalog() {
  return useQuery({ queryKey: ["permissions"], queryFn: fetchPermissionCatalog, staleTime: 5 * 60_000 });
}

function useRoleMutation<TArgs>(mutationFn: (args: TArgs) => Promise<unknown>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: rolesKey });
      // The current user's own permissions may have changed.
      queryClient.invalidateQueries({ queryKey: sessionQueryKey });
    },
  });
}

export function useCreateRole() {
  return useRoleMutation((input: RoleInput) => createRole(input));
}

export function useUpdateRole(id: string) {
  return useRoleMutation((input: Partial<RoleInput>) => updateRole(id, input));
}

export function useDeleteRole() {
  return useRoleMutation((id: string) => deleteRole(id));
}
