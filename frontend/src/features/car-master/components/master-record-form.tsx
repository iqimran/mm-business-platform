"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMemo } from "react";
import { Controller, useForm } from "react-hook-form";
import { z } from "zod";
import { FieldError, FormAlert } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { applyApiErrors } from "@/lib/form-errors";
import type { MasterRecord } from "../api";
import type { MasterResource } from "../config";
import { useSaveRecord } from "../hooks";

type FormValues = Record<string, string | boolean>;

function buildSchema(resource: MasterResource) {
  const shape: Record<string, z.ZodType<string | boolean, string | boolean>> = { is_active: z.boolean() };
  for (const field of resource.fields) {
    let rule = z.string().trim().max(field.max, `${field.label} must be at most ${field.max} characters.`);
    if (field.required) rule = rule.min(1, `${field.label} is required.`);
    shape[field.name] =
      field.type === "email"
        ? rule.refine((v) => v === "" || z.email().safeParse(v).success, "Enter a valid email address.")
        : rule;
  }
  return z.object(shape);
}

export function MasterRecordForm({ resource, record, onDone }: { resource: MasterResource; record?: MasterRecord; onDone: () => void }) {
  const save = useSaveRecord(resource);
  const schema = useMemo(() => buildSchema(resource), [resource]);
  const fieldNames = resource.fields.map((f) => f.name);
  const prefix = `${resource.path}-${record?.id ?? "new"}`;

  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      is_active: record?.is_active ?? true,
      ...Object.fromEntries(resource.fields.map((f) => [f.name, String(record?.[f.name] ?? "")])),
    },
  });

  const submit = handleSubmit(async (values) => {
    // Empty optional fields are sent as null so they can be cleared.
    const input = Object.fromEntries(
      Object.entries(values).map(([key, value]) => [key, typeof value === "string" && value === "" ? null : value]),
    );
    try {
      await save.mutateAsync({ id: record?.id, input });
      onDone();
    } catch (error) {
      applyApiErrors(error, setError, [...fieldNames, "is_active"]);
    }
  });

  const errorFor = (name: string) => errors[name]?.message as string | undefined;

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4 rounded-lg border bg-muted/30 p-4">
      <FormAlert message={errors.root?.message} />
      <div className="grid gap-4 sm:grid-cols-2">
        {resource.fields.map((field) => {
          const id = `${prefix}-${field.name}`;
          const wide = field.type === "textarea";
          return (
            <div key={field.name} className={wide ? "flex flex-col gap-2 sm:col-span-2" : "flex flex-col gap-2"}>
              <Label htmlFor={id}>
                {field.label}
                {field.required ? <span aria-hidden className="text-destructive">*</span> : null}
              </Label>
              {wide ? (
                <Textarea id={id} rows={2} aria-invalid={errorFor(field.name) ? true : undefined} {...register(field.name)} />
              ) : (
                <Input id={id} type={field.type} aria-invalid={errorFor(field.name) ? true : undefined} {...register(field.name)} />
              )}
              <FieldError id={`${id}-error`} message={errorFor(field.name)} />
            </div>
          );
        })}
      </div>
      <Controller
        control={control}
        name="is_active"
        render={({ field }) => (
          <div className="flex items-center gap-2">
            <Checkbox id={`${prefix}-active`} checked={field.value === true} onCheckedChange={(checked) => field.onChange(checked)} />
            <Label htmlFor={`${prefix}-active`} className="font-normal">
              Active (available for new records)
            </Label>
          </div>
        )}
      />
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onDone}>
          Cancel
        </Button>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? "Saving…" : record ? "Save" : `Add ${resource.singular}`}
        </Button>
      </div>
    </form>
  );
}
