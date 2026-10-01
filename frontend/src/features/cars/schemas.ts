import { z } from "zod";
import type { Car, CarInput } from "./api";

const maxYear = new Date().getFullYear() + 1;

const optionalText = (label: string, max: number) => z.string().trim().max(max, `${label} must be at most ${max} characters.`);

const optionalWholeNumber = (min: number, max: number, message: string) =>
  z
    .string()
    .trim()
    .refine((v) => v === "" || (/^\d+$/.test(v) && Number(v) >= min && Number(v) <= max), message);

/** Mirrors the API rules for fast feedback; the API remains authoritative. */
export const carSchema = z.object({
  branch_id: z.string().min(1, "Select a branch."),
  dealer_id: z.string(),
  brand: z.string().trim().min(1, "Brand is required.").max(100, "Brand must be at most 100 characters."),
  model: z.string().trim().min(1, "Model is required.").max(100, "Model must be at most 100 characters."),
  model_year: optionalWholeNumber(1900, maxYear, `Enter a year between 1900 and ${maxYear}.`),
  color: optionalText("Color", 50),
  chassis_number: z.string().trim().min(1, "Chassis number is required.").max(50, "Chassis number must be at most 50 characters."),
  engine_number: optionalText("Engine number", 50),
  registration_number: optionalText("Registration number", 30),
  mileage_km: optionalWholeNumber(0, 2_000_000, "Enter a valid mileage."),
  notes: optionalText("Notes", 5000),
});

export type CarFormValues = z.infer<typeof carSchema>;

const orNull = (v: string) => (v === "" ? null : v);

export function toCarInput(values: CarFormValues): CarInput {
  return {
    branch_id: values.branch_id,
    dealer_id: orNull(values.dealer_id),
    brand: values.brand,
    model: values.model,
    model_year: values.model_year === "" ? null : Number(values.model_year),
    color: orNull(values.color),
    chassis_number: values.chassis_number,
    engine_number: orNull(values.engine_number),
    registration_number: orNull(values.registration_number),
    mileage_km: values.mileage_km === "" ? null : Number(values.mileage_km),
    notes: orNull(values.notes),
  };
}

export function toCarFormValues(car: Car | undefined, defaultBranchId: string): CarFormValues {
  return {
    branch_id: car?.branch?.id ?? defaultBranchId,
    dealer_id: car?.dealer?.id ?? "",
    brand: car?.brand ?? "",
    model: car?.model ?? "",
    model_year: car?.model_year?.toString() ?? "",
    color: car?.color ?? "",
    chassis_number: car?.chassis_number ?? "",
    engine_number: car?.engine_number ?? "",
    registration_number: car?.registration_number ?? "",
    mileage_km: car?.mileage_km?.toString() ?? "",
    notes: car?.notes ?? "",
  };
}
