"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { createBackup, fetchBackups } from "./api";

const backupsKey = ["database-backups"] as const;

/** Polls every 3 s while any listed backup is still pending/running. */
export function useBackups(page: number) {
  return useQuery({
    queryKey: [...backupsKey, page],
    queryFn: () => fetchBackups(page),
    placeholderData: keepPreviousData,
    refetchInterval: (query) => (query.state.data?.items.some((b) => b.status === "pending" || b.status === "running") ? 3000 : false),
  });
}

export function useCreateBackup() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: createBackup,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: backupsKey }),
  });
}
