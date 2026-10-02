import { apiRequest } from "@/lib/api-client";

/** Shown until the configured name has loaded (and if the API is unreachable). */
export const DEFAULT_APP_NAME = "MM Business";

/** Public branding (display name only); available before sign-in. */
export function fetchAppInfo() {
  return apiRequest<{ name: string }>("/app-info");
}
