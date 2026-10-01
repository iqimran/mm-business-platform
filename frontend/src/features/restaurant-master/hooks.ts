"use client";

import { keepPreviousData, type QueryClient, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { createRecord, deleteRecord, fetchMenuCategories, fetchRecords, updateRecord, type MasterFilters } from "./api";
import type { MasterResource } from "./config";

/** Menu item rows show their category's name, so category changes refresh the menu too. */
function invalidate(queryClient: QueryClient, resource: MasterResource) {
  queryClient.invalidateQueries({ queryKey: [resource.path] });
  if (resource.path === "restaurant/menu-categories") queryClient.invalidateQueries({ queryKey: ["restaurant/menu-items"] });
}

export function useMasterRecords(resource: MasterResource, filters: MasterFilters) {
  return useQuery({
    queryKey: [resource.path, filters],
    queryFn: () => fetchRecords(resource, filters),
    placeholderData: keepPreviousData,
  });
}

/** Categories for the item form (active only) or the list filter (all). */
export function useMenuCategories(activeOnly: boolean, enabled = true) {
  return useQuery({
    queryKey: ["restaurant/menu-categories", "options", activeOnly],
    queryFn: () => fetchMenuCategories(activeOnly),
    enabled,
    staleTime: 60_000,
  });
}

export function useSaveRecord(resource: MasterResource) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id?: string; input: Record<string, unknown> }) =>
      id ? updateRecord(resource, id, input) : createRecord(resource, input),
    onSuccess: () => invalidate(queryClient, resource),
  });
}

export function useDeleteRecord(resource: MasterResource) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => deleteRecord(resource, id),
    onSuccess: () => invalidate(queryClient, resource),
  });
}
