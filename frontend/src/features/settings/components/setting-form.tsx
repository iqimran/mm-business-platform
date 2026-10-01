"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Controller, useForm, useWatch } from "react-hook-form";
import { FieldError, FormAlert } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { applyApiErrors } from "@/lib/form-errors";
import type { Setting } from "../api";
import { useSaveSetting } from "../hooks";
import { settingSchema, toFormValues, toValue, valueTypeLabels, valueTypes, type SettingFormValues } from "../schemas";

type SettingFormProps = {
  /** Existing setting to edit; omit to create a new one. */
  setting?: Setting;
  onDone: () => void;
};

const selectClass =
  "h-8 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 dark:bg-input/30";

export function SettingForm({ setting, onDone }: SettingFormProps) {
  const save = useSaveSetting();
  const isNew = !setting;
  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<SettingFormValues>({
    resolver: zodResolver(settingSchema),
    defaultValues: setting
      ? toFormValues(setting.key, setting.value, setting.description)
      : { key: "", type: "text", raw: "", enabled: false, description: "" },
  });
  const type = useWatch({ control, name: "type" });
  const prefix = setting ? `setting-${setting.key}` : "setting-new";

  const submit = handleSubmit(async (values) => {
    try {
      await save.mutateAsync({
        key: values.key,
        input: { value: toValue(values), description: values.description || null },
      });
      onDone();
    } catch (error) {
      applyApiErrors(error, setError, ["description"], { value: "raw" });
    }
  });

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4 rounded-lg border bg-muted/30 p-4">
      <FormAlert message={errors.root?.message} />

      <div className="grid gap-4 sm:grid-cols-[2fr_1fr]">
        <div className="flex flex-col gap-2">
          <Label htmlFor={`${prefix}-key`}>Key</Label>
          <Input
            id={`${prefix}-key`}
            placeholder="app.name"
            readOnly={!isNew}
            className="font-mono"
            aria-invalid={errors.key ? true : undefined}
            {...register("key")}
          />
          <FieldError id={`${prefix}-key-error`} message={errors.key?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor={`${prefix}-type`}>Type</Label>
          <select id={`${prefix}-type`} className={selectClass} {...register("type")}>
            {valueTypes.map((t) => (
              <option key={t} value={t}>
                {valueTypeLabels[t]}
              </option>
            ))}
          </select>
        </div>
      </div>

      <div className="flex flex-col gap-2">
        {type === "boolean" ? (
          <Controller
            control={control}
            name="enabled"
            render={({ field }) => (
              <div className="flex items-center gap-2">
                <Checkbox id={`${prefix}-value`} checked={field.value} onCheckedChange={(checked) => field.onChange(checked)} />
                <Label htmlFor={`${prefix}-value`} className="font-normal">
                  Enabled
                </Label>
              </div>
            )}
          />
        ) : (
          <>
            <Label htmlFor={`${prefix}-value`}>Value</Label>
            {type === "json" ? (
              <Textarea id={`${prefix}-value`} rows={5} className="font-mono text-xs" aria-invalid={errors.raw ? true : undefined} {...register("raw")} />
            ) : (
              <Input
                id={`${prefix}-value`}
                inputMode={type === "number" ? "decimal" : undefined}
                aria-invalid={errors.raw ? true : undefined}
                {...register("raw")}
              />
            )}
            <FieldError id={`${prefix}-value-error`} message={errors.raw?.message} />
          </>
        )}
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor={`${prefix}-description`}>Description</Label>
        <Input id={`${prefix}-description`} aria-invalid={errors.description ? true : undefined} {...register("description")} />
        <FieldError id={`${prefix}-description-error`} message={errors.description?.message} />
      </div>

      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onDone}>
          Cancel
        </Button>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? "Saving…" : isNew ? "Add setting" : "Save"}
        </Button>
      </div>
    </form>
  );
}
