"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { NativeSelect } from "@/components/common/native-select";
import { FieldError, FormAlert } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { usePermissions, useSession } from "@/features/auth/hooks";
import { dealerResource } from "@/features/car-master/config";
import { useActiveOptions } from "@/features/car-master/hooks";
import { applyApiErrors } from "@/lib/form-errors";
import type { Car, CarInput } from "../api";
import { carSchema, toCarFormValues, toCarInput, type CarFormValues } from "../schemas";

type CarFormProps = {
  car?: Car;
  submitLabel: string;
  onSubmit: (input: CarInput) => Promise<unknown>;
  onCancel: () => void;
};

const textFields = [
  { name: "brand", label: "Brand", required: true },
  { name: "model", label: "Model", required: true },
  { name: "model_year", label: "Model year", inputMode: "numeric" },
  { name: "color", label: "Color" },
  { name: "chassis_number", label: "Chassis number", required: true, hint: "Stored in uppercase without spaces." },
  { name: "engine_number", label: "Engine number" },
  { name: "registration_number", label: "Registration number" },
  { name: "mileage_km", label: "Mileage (km)", inputMode: "numeric" },
] as const;

export function CarForm({ car, submitLabel, onSubmit, onCancel }: CarFormProps) {
  const { data: session } = useSession();
  const { can } = usePermissions();
  const branches = session?.branches ?? [];
  const canPickDealer = can("car.dealer.view");
  const dealers = useActiveOptions(dealerResource, canPickDealer);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<CarFormValues>({
    resolver: zodResolver(carSchema),
    defaultValues: toCarFormValues(car, branches.length === 1 ? branches[0].id : ""),
  });

  const submit = handleSubmit(async (values) => {
    try {
      await onSubmit(toCarInput(values));
    } catch (error) {
      applyApiErrors(error, setError, ["branch_id", "dealer_id", ...textFields.map((f) => f.name), "notes"]);
    }
  });

  // Keep the current (possibly inactive) dealer selectable when editing.
  const dealerOptions = [...(dealers.data ?? [])];
  if (car?.dealer && !dealerOptions.some((d) => d.id === car.dealer?.id)) {
    dealerOptions.unshift({ id: car.dealer.id, name: `${car.dealer.name} (inactive)`, is_active: false });
  }

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-6">
      <FormAlert message={errors.root?.message} />

      <Card>
        <CardHeader>
          <CardTitle>Location & source</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-2">
            <Label htmlFor="car-branch">
              Branch<span aria-hidden className="text-destructive">*</span>
            </Label>
            <NativeSelect id="car-branch" aria-invalid={errors.branch_id ? true : undefined} {...register("branch_id")}>
              <option value="">Select a branch…</option>
              {branches.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.code} — {b.name}
                </option>
              ))}
            </NativeSelect>
            <FieldError id="car-branch-error" message={errors.branch_id?.message} />
          </div>
          {canPickDealer ? (
            <div className="flex flex-col gap-2">
              <Label htmlFor="car-dealer">Dealer</Label>
              <NativeSelect id="car-dealer" aria-invalid={errors.dealer_id ? true : undefined} {...register("dealer_id")}>
                <option value="">No dealer</option>
                {dealerOptions.map((d) => (
                  <option key={d.id} value={d.id}>
                    {d.name}
                  </option>
                ))}
              </NativeSelect>
              <FieldError id="car-dealer-error" message={errors.dealer_id?.message} />
            </div>
          ) : null}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Vehicle</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-2">
          {textFields.map((field) => {
            const id = `car-${field.name}`;
            const message = errors[field.name]?.message;
            return (
              <div key={field.name} className="flex flex-col gap-2">
                <Label htmlFor={id}>
                  {field.label}
                  {"required" in field ? <span aria-hidden className="text-destructive">*</span> : null}
                </Label>
                <Input
                  id={id}
                  inputMode={"inputMode" in field ? field.inputMode : undefined}
                  aria-invalid={message ? true : undefined}
                  {...register(field.name)}
                />
                {"hint" in field && !message ? <p className="text-xs text-muted-foreground">{field.hint}</p> : null}
                <FieldError id={`${id}-error`} message={message} />
              </div>
            );
          })}
          <div className="flex flex-col gap-2 sm:col-span-2">
            <Label htmlFor="car-notes">Notes</Label>
            <Textarea id="car-notes" rows={3} aria-invalid={errors.notes ? true : undefined} {...register("notes")} />
            <FieldError id="car-notes-error" message={errors.notes?.message} />
          </div>
        </CardContent>
      </Card>

      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? "Saving…" : submitLabel}
        </Button>
      </div>
    </form>
  );
}
