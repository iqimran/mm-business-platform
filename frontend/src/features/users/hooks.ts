"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { sessionQueryKey, usePermissions, useSession } from "@/features/auth/hooks";
import { useBranchOptions } from "@/features/branches/hooks";
import { useRoles } from "@/features/roles/hooks";
import {
  createUser,
  deleteUser,
  fetchUser,
  fetchUsers,
  syncUserBranches,
  syncUserRoles,
  updateUser,
  type NewUserInput,
  type UserFilters,
  type UserUpdateInput,
} from "./api";

const usersKey = ["users"] as const;

export function useUsers(filters: UserFilters) {
  return useQuery({ queryKey: [...usersKey, "list", filters], queryFn: () => fetchUsers(filters), placeholderData: keepPreviousData });
}

export function useUser(id: string) {
  return useQuery({ queryKey: [...usersKey, "detail", id], queryFn: () => fetchUser(id) });
}

function useUserMutation<TArgs, TResult>(mutationFn: (args: TArgs) => Promise<TResult>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: usersKey });
      queryClient.invalidateQueries({ queryKey: ["branches"] }); // staff counts
      queryClient.invalidateQueries({ queryKey: ["roles"] }); // user counts
      queryClient.invalidateQueries({ queryKey: sessionQueryKey }); // in case the admin edited themselves
    },
  });
}

export const useCreateUser = () => useUserMutation((input: NewUserInput) => createUser(input));
export const useUpdateUser = (id: string) => useUserMutation((input: UserUpdateInput) => updateUser(id, input));
export const useDeleteUser = () => useUserMutation((id: string) => deleteUser(id));
export const useSyncUserRoles = (id: string) => useUserMutation((roleIds: string[]) => syncUserRoles(id, roleIds));
export const useSyncUserBranches = (id: string) => useUserMutation((branchIds: string[]) => syncUserBranches(id, branchIds));

/**
 * Role and branch choices for assignment. Branches come from the branch list when the admin
 * may view branches, otherwise from their own accessible branches; the API validates either way.
 */
export function useAssignmentOptions() {
  const { can } = usePermissions();
  const { data: session } = useSession();
  const roles = useRoles();
  const branchList = useBranchOptions(can("branch.view"));

  const branches = can("branch.view") ? (branchList.data ?? []) : (session?.branches ?? []);

  return {
    roles: can("role.view") ? (roles.data ?? []) : null,
    branches: branches.map((b) => ({ id: b.id, label: `${b.code} — ${b.name}`, inactive: "is_active" in b && b.is_active === false })),
  };
}
