import { apiRequest } from "@/lib/api-client";

export type SettingValue = string | number | boolean | null | SettingValue[] | { [key: string]: SettingValue };

export type Setting = {
  key: string;
  value: SettingValue;
  description: string | null;
  updated_at: string | null;
};

export type SettingInput = {
  value: SettingValue;
  description?: string | null;
};

export function fetchSettings() {
  return apiRequest<Setting[]>("/settings");
}

/** Creates the setting when the key does not exist yet. */
export function saveSetting(key: string, input: SettingInput) {
  return apiRequest<Setting>(`/settings/${encodeURIComponent(key)}`, { method: "PUT", body: input });
}
