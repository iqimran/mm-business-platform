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

export type BusinessModule = "car" | "restaurant";

/** Letterhead (name, address, contact) printed on that business's documents and exports. */
export type BusinessProfile = {
  module: BusinessModule;
  label: string;
  name: string | null;
  address: string | null;
  phone: string | null;
  email: string | null;
  configured: boolean;
};

export type BusinessProfileInput = { name: string; address: string | null; phone: string | null; email: string | null };

export function fetchBusinessProfiles() {
  return apiRequest<BusinessProfile[]>("/business-profiles");
}

export function saveBusinessProfile(module: BusinessModule, input: BusinessProfileInput) {
  return apiRequest<BusinessProfile>(`/business-profiles/${module}`, { method: "PUT", body: input });
}

/** Business profiles have their own form; hide their raw keys from the generic settings list. */
export const isBusinessProfileKey = (key: string) => key.startsWith("business_profile.");
