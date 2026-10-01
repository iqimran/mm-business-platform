"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMemo } from "react";
import { Controller, useForm } from "react-hook-form";
import { FieldError, FormAlert } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { applyApiErrors } from "@/lib/form-errors";
import type { MasterRecord } from "../api";
import type { MasterField, MasterResource } from "../config";
import { useSaveRecord } from "../hooks";
import { buildSchema, toPayload, type FormValues } from "../schemas";
import { CategorySelect } from "./category-select";

function FieldInput({ field, id, invalid, record, register }: {
  field: MasterField;
  id: string;
  invalid: boolean;
  record?: MasterRecord;
  register: ReturnType<typeof useForm<FormValues>>["register"];
}) {
  const common = { id, "aria-invalid": invalid ? true : undefined, ...register(field.name) };

  switch (field.type) {
    case "textarea":
      return <Textarea rows={2} {...common} />;
    case "category":
      return <CategorySelect current={record?.category} {...common} />;
    case "money":
      return <Input inputMode="decimal" placeholder="0.00" {...common} />;
    case "number":
      return <Input inputMode="numeric" placeholder="0" {...common} />;
    default:
      return <Input type={field.type} {...common} />;
  }
}

export function MasterRecordForm({ resource, record, onDone }: { resource: MasterResource; record?: MasterRecord; onDone: () => void }) {
  const save = useSaveRecord(resource);
  const schema = useMemo(() => buildSchema(resource), [resource]);
  const fieldNames = resource.fields.map((f) => f.name);
  const prefix = `${resource.path.replace("/", "-")}-${record?.id ?? "new"}`;

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
    try {
      await save.mutateAsync({ id: record?.id, input: toPayload(resource, values) });
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
          return (
            <div key={field.name} className={field.type === "textarea" ? "flex flex-col gap-2 sm:col-span-2" : "flex flex-col gap-2"}>
              <Label htmlFor={id}>
                {field.label}
                {field.required ? <span aria-hidden className="text-destructive">*</span> : null}
              </Label>
              <FieldInput field={field} id={id} invalid={Boolean(errorFor(field.name))} record={record} register={register} />
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
              {resource.path === "restaurant/menu-items" ? "Available (can be sold)" : "Active (available for new records)"}
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
