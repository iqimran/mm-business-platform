"use client";

import { useQuery } from "@tanstack/react-query";
import { useEffect } from "react";
import { DEFAULT_APP_NAME, fetchAppInfo } from "./api";

export const appInfoQueryKey = ["app-info"] as const;

/** Application display name from Settings ("app.name"). */
export function useAppName(): string {
  const info = useQuery({ queryKey: appInfoQueryKey, queryFn: fetchAppInfo, staleTime: 5 * 60_000, retry: 1 });
  return info.data?.name || DEFAULT_APP_NAME;
}

/** Keeps the browser tab title in sync with the configured application name. */
export function AppTitle() {
  const name = useAppName();
  useEffect(() => {
    document.title = name;
  }, [name]);
  return null;
}
