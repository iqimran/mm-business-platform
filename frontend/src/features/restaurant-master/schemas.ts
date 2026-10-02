import { z } from "zod";
import { AMOUNT_PATTERN } from "@/features/restaurant-common/money";
import type { MasterResource } from "./config";

export type FormValues = Record<string, string | boolean>;


export function buildSchema(resource: MasterResource) {
  const shape: Record<string, z.ZodType<string | boolean, string | boolean>> = { is_active: z.boolean() };

  for (const field of resource.fields) {
    let rule = z.string().trim();

    if (field.type === "money") {
      shape[field.name] = rule
        .min(1, `${field.label} is required.`)
        .regex(AMOUNT_PATTERN, `${field.label} must be an amount with at most 2 decimal places, e.g. 250.00.`)
        .refine((v) => !AMOUNT_PATTERN.test(v) || /[1-9]/.test(v), `${field.label} must be greater than zero.`);
      continue;
    }
    if (field.type === "number") {
      shape[field.name] = rule.refine(
        (v) => v === "" || (/^\d+$/.test(v) && Number(v) >= (field.nullable ? 1 : 0) && Number(v) <= field.max),
        `${field.label} must be a whole number between ${field.nullable ? 1 : 0} and ${field.max}.`,
      );
      continue;
    }

    rule = rule.max(field.max, `${field.label} must be at most ${field.max} characters.`);
    const select = field.type === "category" || field.type === "branch";
    if (field.required) rule = rule.min(1, select ? `Select a ${field.label.toLowerCase()}.` : `${field.label} is required.`);
    shape[field.name] = rule;
  }

  return z.object(shape);
}

/**
 * Form values → API payload: empty optional text is sent as null (clears it); numbers as integers.
 * Empty number fields are omitted (server default), or sent as null when the field is nullable.
 */
export function toPayload(resource: MasterResource, values: FormValues): Record<string, unknown> {
  const types = Object.fromEntries(resource.fields.map((f) => [f.name, f.type]));
  const nullable = new Set(resource.fields.filter((f) => f.nullable).map((f) => f.name));

  return Object.fromEntries(
    Object.entries(values)
      .filter(([key, value]) => !(types[key] === "number" && value === "" && !nullable.has(key)))
      .map(([key, value]) => {
        if (typeof value !== "string") return [key, value];
        const trimmed = value.trim();
        if (types[key] === "number") return [key, trimmed === "" ? null : Number(trimmed)];
        return [key, trimmed === "" ? null : trimmed];
      }),
  );
}
