"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMemo } from "react";
import { Controller, useForm } from "react-hook-form";
import { FieldError, FormAlert } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { usePermissions } from "@/features/auth/hooks";
import { applyApiErrors } from "@/lib/form-errors";
import type { Permission, Role, RoleInput } from "../api";
import { roleSchema, type RoleValues } from "../schemas";

type RoleFormProps = {
  role?: Role;
  catalog: Permission[];
  submitLabel: string;
  onSubmit: (input: RoleInput) => Promise<unknown>;
  onCancel: () => void;
};

export function RoleForm({ role, catalog, submitLabel, onSubmit, onCancel }: RoleFormProps) {
  const { granted } = usePermissions();
  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<RoleValues>({
    resolver: zodResolver(roleSchema),
    defaultValues: {
      name: role?.name ?? "",
      description: role?.description ?? "",
      permissions: role?.permissions ?? [],
    },
  });

  const modules = useMemo(() => {
    const grouped = new Map<string, Permission[]>();
    for (const permission of catalog) {
      grouped.set(permission.module, [...(grouped.get(permission.module) ?? []), permission]);
    }
    return [...grouped.entries()];
  }, [catalog]);

  const submit = handleSubmit(async (values) => {
    try {
      await onSubmit({ name: values.name, description: values.description || null, permissions: values.permissions });
    } catch (error) {
      applyApiErrors(error, setError, ["name", "description", "permissions"]);
    }
  });

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-6">
      <FormAlert message={errors.root?.message} />

      <Card>
        <CardHeader>
          <CardTitle>Details</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div className="flex flex-col gap-2">
            <Label htmlFor="role-name">Name</Label>
            <Input
              id="role-name"
              readOnly={role?.is_system}
              aria-invalid={errors.name ? true : undefined}
              aria-describedby="role-name-help role-name-error"
              {...register("name")}
            />
            {role?.is_system ? (
              <p id="role-name-help" className="text-xs text-muted-foreground">
                System roles cannot be renamed.
              </p>
            ) : null}
            <FieldError id="role-name-error" message={errors.name?.message} />
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="role-description">Description</Label>
            <Textarea id="role-description" rows={2} aria-invalid={errors.description ? true : undefined} {...register("description")} />
            <FieldError id="role-description-error" message={errors.description?.message} />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Permissions</CardTitle>
          <CardDescription>You can only grant permissions you hold yourself; others are disabled.</CardDescription>
        </CardHeader>
        <CardContent>
          <Controller
            control={control}
            name="permissions"
            render={({ field }) => {
              const selected = new Set(field.value);
              const toggle = (name: string, on: boolean) => {
                const next = new Set(selected);
                if (on) next.add(name);
                else next.delete(name);
                field.onChange([...next].sort());
              };

              return (
                <div className="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
                  {modules.map(([module, permissions]) => {
                    const grantable = permissions.filter((p) => granted.has(p.name));
                    const allOn = grantable.length > 0 && grantable.every((p) => selected.has(p.name));

                    return (
                      <fieldset key={module} className="flex flex-col gap-2">
                        <legend className="mb-1 flex w-full items-center justify-between gap-2 text-sm font-medium capitalize">
                          {module}
                          {grantable.length > 0 ? (
                            <button
                              type="button"
                              className="text-xs font-normal text-muted-foreground underline-offset-2 hover:underline"
                              onClick={() => grantable.forEach((p) => toggle(p.name, !allOn))}
                            >
                              {allOn ? "Clear" : "Select all"}
                            </button>
                          ) : null}
                        </legend>
                        {permissions.map((permission) => {
                          const id = `perm-${permission.name}`;
                          const disabled = !granted.has(permission.name);
                          return (
                            <div key={permission.name} className="flex items-start gap-2">
                              <Checkbox
                                id={id}
                                checked={selected.has(permission.name)}
                                disabled={disabled}
                                onCheckedChange={(checked) => toggle(permission.name, checked)}
                                className="mt-0.5"
                              />
                              <Label htmlFor={id} className="flex flex-col items-start gap-0.5 font-normal">
                                <span className="font-mono text-xs">{permission.name}</span>
                                {permission.description ? (
                                  <span className="text-xs text-muted-foreground">{permission.description}</span>
                                ) : null}
                              </Label>
                            </div>
                          );
                        })}
                      </fieldset>
                    );
                  })}
                </div>
              );
            }}
          />
          <FieldError id="role-permissions-error" message={errors.permissions?.message} />
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
