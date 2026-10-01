"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { sessionQueryKey } from "@/features/auth/hooks";
import { createBranch, fetchBranchOptions, fetchBranches, updateBranch, type BranchFilters, type BranchInput } from "./api";

const branchesKey = ["branches"] as const;

export function useBranches(filters: BranchFilters) {
  return useQuery({ queryKey: [...branchesKey, filters], queryFn: () => fetchBranches(filters), placeholderData: keepPreviousData });
}

export function useBranchOptions(enabled: boolean) {
  return useQuery({ queryKey: [...branchesKey, "options"], queryFn: fetchBranchOptions, enabled, staleTime: 60_000 });
}

export function useSaveBranch() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id?: string; input: Partial<BranchInput> }) => (id ? updateBranch(id, input) : createBranch(input as BranchInput)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: branchesKey });
      // Branch pickers and the user's own accessible branches come from the session.
      queryClient.invalidateQueries({ queryKey: sessionQueryKey });
    },
  });
}
