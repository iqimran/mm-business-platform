import { z } from "zod";
import type { SettingValue } from "./api";

export const valueTypes = ["text", "number", "boolean", "json"] as const;
export type ValueType = (typeof valueTypes)[number];

export const valueTypeLabels: Record<ValueType, string> = {
  text: "Text",
  number: "Number",
  boolean: "Yes / No",
  json: "JSON",
};

/** Mirrors the backend settings_key_format_check constraint, e.g. "app.name". */
const KEY_PATTERN = /^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/;

export const settingSchema = z
  .object({
    key: z
      .string()
      .trim()
      .max(150, "Key must be at most 150 characters.")
      .regex(KEY_PATTERN, 'Use lowercase dot-separated words, e.g. "app.name".'),
    type: z.enum(valueTypes),
    raw: z.string(),
    enabled: z.boolean(),
    description: z.string().trim().max(255, "Description must be at most 255 characters."),
  })
  .superRefine((values, ctx) => {
    if (values.type === "number" && (values.raw.trim() === "" || !Number.isFinite(Number(values.raw)))) {
      ctx.addIssue({ code: "custom", path: ["raw"], message: "Enter a valid number." });
    }
    if (values.type === "json") {
      try {
        JSON.parse(values.raw);
      } catch {
        ctx.addIssue({ code: "custom", path: ["raw"], message: "Enter valid JSON." });
      }
    }
  });

export type SettingFormValues = z.infer<typeof settingSchema>;

export function inferType(value: SettingValue): ValueType {
  if (typeof value === "number") return "number";
  if (typeof value === "boolean") return "boolean";
  if (typeof value === "string") return "text";
  return "json";
}

export function toFormValues(key: string, value: SettingValue, description: string | null): SettingFormValues {
  const type = inferType(value);
  return {
    key,
    type,
    raw: type === "json" ? JSON.stringify(value, null, 2) : type === "boolean" ? "" : String(value ?? ""),
    enabled: value === true,
    description: description ?? "",
  };
}

export function toValue(values: SettingFormValues): SettingValue {
  switch (values.type) {
    case "number":
      return Number(values.raw);
    case "boolean":
      return values.enabled;
    case "json":
      return JSON.parse(values.raw) as SettingValue;
    default:
      return values.raw;
  }
}

export function formatValue(value: SettingValue): string {
  if (typeof value === "boolean") return value ? "Yes" : "No";
  if (typeof value === "string") return value;
  return JSON.stringify(value);
}

/** Mirrors the API rules for business profiles; the API remains the source of truth. */
export const businessProfileSchema = z.object({
  name: z.string().trim().min(1, "Business name is required.").max(150, "Business name must be at most 150 characters."),
  address: z.string().trim().max(500, "Address must be at most 500 characters."),
  phone: z
    .string()
    .trim()
    .max(100, "Phone must be at most 100 characters.")
    .refine((v) => v === "" || /^[0-9+()\-\s,/]+$/.test(v), "Use digits, spaces and + ( ) - , / only (several numbers may be separated by commas)."),
  email: z.string().trim().max(255).refine((v) => v === "" || z.email().safeParse(v).success, "Enter a valid email address."),
});

export type BusinessProfileValues = z.infer<typeof businessProfileSchema>;

export function toBusinessProfileInput(v: BusinessProfileValues) {
  return { name: v.name.trim(), address: v.address.trim() || null, phone: v.phone.trim() || null, email: v.email.trim() || null };
}
