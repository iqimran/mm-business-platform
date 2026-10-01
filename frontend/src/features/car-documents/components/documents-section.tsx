"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Pencil, Plus, Trash2 } from "lucide-react";
import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { z } from "zod";
import { NativeSelect } from "@/components/common/native-select";
import { FieldError, FormAlert } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { usePermissions } from "@/features/auth/hooks";
import { applyApiErrors, errorMessage } from "@/lib/form-errors";
import { documentTypeLabels, documentTypes, type CarDocument } from "../api";
import { useCarDocuments, useDeleteCarDocument, useSaveCarDocument } from "../hooks";
import { ExpiryBadge } from "./expiry-badge";

const schema = z
  .object({
    type: z.enum(documentTypes),
    custom_name: z.string().trim().max(100, "Name must be at most 100 characters."),
    document_number: z.string().trim().max(100, "Number must be at most 100 characters."),
    issue_date: z.string(),
    expiry_date: z.string().min(1, "Expiry date is required."),
    notes: z.string().trim().max(5000, "Notes must be at most 5000 characters."),
  })
  .superRefine((v, ctx) => {
    if (v.type === "other" && v.custom_name === "") {
      ctx.addIssue({ code: "custom", path: ["custom_name"], message: "Enter a document name." });
    }
    if (v.issue_date && v.expiry_date && v.expiry_date < v.issue_date) {
      ctx.addIssue({ code: "custom", path: ["expiry_date"], message: "Expiry must be on or after the issue date." });
    }
  });

type Values = z.infer<typeof schema>;

function DocumentForm({ carId, document, onDone }: { carId: string; document?: CarDocument; onDone: () => void }) {
  const save = useSaveCarDocument(carId);
  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      type: document?.type ?? "fitness",
      custom_name: document?.custom_name ?? "",
      document_number: document?.document_number ?? "",
      issue_date: document?.issue_date ?? "",
      expiry_date: document?.expiry_date ?? "",
      notes: document?.notes ?? "",
    },
  });
  const type = useWatch({ control, name: "type" });
  const prefix = `doc-${document?.id ?? "new"}`;

  const submit = handleSubmit(async (v) => {
    try {
      await save.mutateAsync({
        id: document?.id,
        input: {
          type: v.type,
          custom_name: v.type === "other" ? v.custom_name : null,
          document_number: v.document_number || null,
          issue_date: v.issue_date || null,
          expiry_date: v.expiry_date,
          notes: v.notes || null,
        },
      });
      onDone();
    } catch (e) {
      applyApiErrors(e, setError, ["type", "custom_name", "document_number", "issue_date", "expiry_date", "notes"]);
    }
  });

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4 rounded-lg border bg-muted/30 p-4">
      <FormAlert message={errors.root?.message} />
      <div className="grid gap-4 sm:grid-cols-3">
        <div className="flex flex-col gap-2">
          <Label htmlFor={`${prefix}-type`}>Document</Label>
          <NativeSelect id={`${prefix}-type`} {...register("type")}>
            {documentTypes.map((t) => (
              <option key={t} value={t}>
                {documentTypeLabels[t]}
              </option>
            ))}
          </NativeSelect>
          <FieldError id={`${prefix}-type-error`} message={errors.type?.message} />
        </div>
        {type === "other" ? (
          <div className="flex flex-col gap-2">
            <Label htmlFor={`${prefix}-name`}>Document name</Label>
            <Input id={`${prefix}-name`} placeholder="e.g. Pollution certificate" aria-invalid={errors.custom_name ? true : undefined} {...register("custom_name")} />
            <FieldError id={`${prefix}-name-error`} message={errors.custom_name?.message} />
          </div>
        ) : null}
        <div className="flex flex-col gap-2">
          <Label htmlFor={`${prefix}-number`}>Number</Label>
          <Input id={`${prefix}-number`} aria-invalid={errors.document_number ? true : undefined} {...register("document_number")} />
          <FieldError id={`${prefix}-number-error`} message={errors.document_number?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor={`${prefix}-issue`}>Issue date</Label>
          <Input id={`${prefix}-issue`} type="date" aria-invalid={errors.issue_date ? true : undefined} {...register("issue_date")} />
          <FieldError id={`${prefix}-issue-error`} message={errors.issue_date?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor={`${prefix}-expiry`}>
            Expiry date<span aria-hidden className="text-destructive">*</span>
          </Label>
          <Input id={`${prefix}-expiry`} type="date" aria-invalid={errors.expiry_date ? true : undefined} {...register("expiry_date")} />
          <FieldError id={`${prefix}-expiry-error`} message={errors.expiry_date?.message} />
        </div>
        <div className="flex flex-col gap-2 sm:col-span-3">
          <Label htmlFor={`${prefix}-notes`}>Notes</Label>
          <Input id={`${prefix}-notes`} aria-invalid={errors.notes ? true : undefined} {...register("notes")} />
          <FieldError id={`${prefix}-notes-error`} message={errors.notes?.message} />
        </div>
      </div>
      {!document ? (
        <p className="text-xs text-muted-foreground">To renew a document, add the new one here. The old entry is kept as history and stops alerting.</p>
      ) : null}
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onDone}>
          Cancel
        </Button>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? "Saving…" : document ? "Save" : "Add document"}
        </Button>
      </div>
    </form>
  );
}

export function DocumentsSection({ carId }: { carId: string }) {
  const { can } = usePermissions();
  const canView = can("car.document.view");
  const documents = useCarDocuments(carId, canView);
  const remove = useDeleteCarDocument(carId);
  const [editing, setEditing] = useState<string | null>(null);
  const [showHistory, setShowHistory] = useState(false);
  const [error, setError] = useState<string>();

  if (!canView) return null;

  const items = documents.data?.items ?? [];
  const current = items.filter((d) => d.is_current);
  const history = items.filter((d) => !d.is_current);
  const visible = showHistory ? items : current;

  const onDelete = (doc: CarDocument) => {
    if (!window.confirm(`Delete ${doc.name} (expiry ${doc.expiry_date})? Use this only for mistaken entries.`)) return;
    setError(undefined);
    remove.mutate(doc.id, { onError: (e) => setError(errorMessage(e)) });
  };

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle>Documents</CardTitle>
          <CardDescription>
            Fitness, tax token, insurance and other papers. Alerts start {documents.data?.alert_days ?? 30} days before expiry.
          </CardDescription>
        </div>
        {can("car.document.create") && editing === null ? (
          <Button variant="outline" size="sm" onClick={() => setEditing("")}>
            <Plus aria-hidden />
            Add document
          </Button>
        ) : null}
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <FormAlert message={error} />
        {editing === "" ? <DocumentForm carId={carId} onDone={() => setEditing(null)} /> : null}
        {documents.isPending ? <p className="text-sm text-muted-foreground">Loading…</p> : null}
        {documents.isError ? <p className="text-sm text-destructive">{errorMessage(documents.error)}</p> : null}
        {documents.data && items.length === 0 ? <p className="text-sm text-muted-foreground">No documents recorded.</p> : null}

        {visible.length > 0 ? (
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Document</TableHead>
                  <TableHead className="hidden sm:table-cell">Number</TableHead>
                  <TableHead className="hidden md:table-cell">Issued</TableHead>
                  <TableHead>Expires</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-20">
                    <span className="sr-only">Actions</span>
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {visible.map((doc) =>
                  editing === doc.id ? (
                    <TableRow key={doc.id}>
                      <TableCell colSpan={6} className="whitespace-normal">
                        <DocumentForm carId={carId} document={doc} onDone={() => setEditing(null)} />
                      </TableCell>
                    </TableRow>
                  ) : (
                    <TableRow key={doc.id} className={doc.is_current ? undefined : "text-muted-foreground"}>
                      <TableCell className="font-medium">{doc.name}</TableCell>
                      <TableCell className="hidden sm:table-cell">{doc.document_number ?? "—"}</TableCell>
                      <TableCell className="hidden tabular-nums md:table-cell">{doc.issue_date ?? "—"}</TableCell>
                      <TableCell className="tabular-nums">{doc.expiry_date}</TableCell>
                      <TableCell>
                        <ExpiryBadge status={doc.status} days={doc.days_remaining} />
                      </TableCell>
                      <TableCell>
                        <div className="flex justify-end gap-1">
                          {can("car.document.update") ? (
                            <Button variant="ghost" size="icon-sm" aria-label={`Edit ${doc.name}`} disabled={editing !== null} onClick={() => setEditing(doc.id)}>
                              <Pencil aria-hidden />
                            </Button>
                          ) : null}
                          {can("car.document.delete") ? (
                            <Button variant="ghost" size="icon-sm" aria-label={`Delete ${doc.name}`} disabled={remove.isPending} onClick={() => onDelete(doc)}>
                              <Trash2 aria-hidden />
                            </Button>
                          ) : null}
                        </div>
                      </TableCell>
                    </TableRow>
                  ),
                )}
              </TableBody>
            </Table>
          </div>
        ) : null}

        {history.length > 0 ? (
          <button type="button" className="w-fit text-sm text-muted-foreground underline-offset-4 hover:underline" onClick={() => setShowHistory(!showHistory)}>
            {showHistory ? "Hide renewal history" : `Show renewal history (${history.length})`}
          </button>
        ) : null}
      </CardContent>
    </Card>
  );
}
