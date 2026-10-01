"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { createRecord, deleteRecord, fetchActiveOptions, fetchRecords, updateRecord, type MasterFilters } from "./api";
import type { MasterResource } from "./config";

export function useMasterRecords(resource: MasterResource, filters: MasterFilters) {
  return useQuery({
    queryKey: [resource.path, filters],
    queryFn: () => fetchRecords(resource, filters),
    placeholderData: keepPreviousData,
  });
}

export function useActiveOptions(resource: MasterResource, enabled = true) {
  return useQuery({
    queryKey: [resource.path, "active-options"],
    queryFn: () => fetchActiveOptions(resource),
    enabled,
    staleTime: 60_000,
  });
}

export function useSaveRecord(resource: MasterResource) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id?: string; input: Record<string, unknown> }) =>
      id ? updateRecord(resource, id, input) : createRecord(resource, input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [resource.path] }),
  });
}

export function useDeleteRecord(resource: MasterResource) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => deleteRecord(resource, id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [resource.path] }),
  });
}
