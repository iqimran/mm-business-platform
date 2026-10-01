"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { fetchSettings, saveSetting, type SettingInput } from "./api";

const settingsKey = ["settings"] as const;

export function useSettings() {
  return useQuery({ queryKey: settingsKey, queryFn: fetchSettings });
}

export function useSaveSetting() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ key, input }: { key: string; input: SettingInput }) => saveSetting(key, input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: settingsKey }),
  });
}
