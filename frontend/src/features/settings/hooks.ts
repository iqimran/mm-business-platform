"use client";

import { appInfoQueryKey } from "@/features/branding/hooks";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { fetchBusinessProfiles, fetchSettings, saveBusinessProfile, saveSetting, type BusinessModule, type BusinessProfileInput, type SettingInput } from "./api";

const settingsKey = ["settings"] as const;

export function useSettings() {
  return useQuery({ queryKey: settingsKey, queryFn: fetchSettings });
}

export function useSaveSetting() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ key, input }: { key: string; input: SettingInput }) => saveSetting(key, input),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: settingsKey });
      // The application name is shown in the sidebar, login page and tab title.
      queryClient.invalidateQueries({ queryKey: appInfoQueryKey });
    },
  });
}

const profilesKey = ["business-profiles"] as const;

export function useBusinessProfiles() {
  return useQuery({ queryKey: profilesKey, queryFn: fetchBusinessProfiles });
}

export function useSaveBusinessProfile(module: BusinessModule) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: BusinessProfileInput) => saveBusinessProfile(module, input),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: profilesKey });
      queryClient.invalidateQueries({ queryKey: settingsKey });
    },
  });
}
