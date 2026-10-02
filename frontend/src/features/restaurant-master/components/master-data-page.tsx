"use client";

import { Pencil, Plus, Trash2 } from "lucide-react";
import { useNotify } from "@/components/common/notifications";
import { useState } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { FormAlert, Forbidden, PageHeader } from "@/components/common/page-header";
import { Pager } from "@/components/common/pager";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { usePermissions } from "@/features/auth/hooks";
import { useDebouncedValue } from "@/hooks/use-debounced-value";
import { errorMessage } from "@/lib/form-errors";
import type { MasterFilters, MasterRecord } from "../api";
import type { MasterResource } from "../config";
import { displayValue } from "../display";
import { useDeleteRecord, useMasterRecords, useMenuCategories } from "../hooks";
import { MasterRecordForm } from "./master-record-form";

/** null = closed, "" = new record, otherwise the id being edited. */
type Editing = string | null;

function CategoryFilter({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  const categories = useMenuCategories(false);

  return (
    <NativeSelect aria-label="Category filter" className="w-48" value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="">All categories</option>
      {(categories.data ?? []).map((c) => (
        <option key={c.id} value={c.id}>
          {c.name}
          {c.is_active ? "" : " (inactive)"}
        </option>
      ))}
    </NativeSelect>
  );
}

export function MasterDataPage({ resource }: { resource: MasterResource }) {
  const { can } = usePermissions();
  const [filters, setFilters] = useState<MasterFilters>({ page: 1, search: "", active: "", categoryId: "" });
  const [editing, setEditing] = useState<Editing>(null);
  const [actionError, setActionError] = useState<string>();
  const search = useDebouncedValue(filters.search);
  const records = useMasterRecords(resource, { ...filters, search });
  const remove = useDeleteRecord(resource);
  const notify = useNotify();

  const canCreate = can(`${resource.permission}.create`);
  const canUpdate = can(`${resource.permission}.update`);
  const canDelete = resource.deletable && can(`${resource.permission}.delete`);
  const columns = resource.fields.filter((f) => f.column);
  const numeric = (type: string) => type === "money" || type === "number";

  if (!can(`${resource.permission}.view`)) return <Forbidden />;

  const onDelete = (record: MasterRecord) => {
    if (!window.confirm(`Delete ${resource.singular} "${record.name}"? This cannot be undone.`)) return;
    setActionError(undefined);
    remove.mutate(record.id, { onSuccess: () => notify(`"${record.name}" deleted.`), onError: (e) => setActionError(errorMessage(e)) });
  };

  const hasFilters = filters.search.trim() !== "" || filters.active !== "" || filters.categoryId !== "";

  return (
    <>
      <PageHeader
        title={resource.title}
        description={resource.description}
        actions={
          canCreate && editing === null ? (
            <Button onClick={() => setEditing("")}>
              <Plus aria-hidden />
              Add {resource.singular}
            </Button>
          ) : null
        }
      />

      {editing === "" ? <MasterRecordForm resource={resource} onDone={() => setEditing(null)} /> : null}

      <div className="flex flex-wrap gap-2">
        <Input
          type="search"
          placeholder="Search…"
          aria-label={`Search ${resource.title.toLowerCase()}`}
          className="max-w-xs"
          value={filters.search}
          onChange={(e) => setFilters({ ...filters, search: e.target.value, page: 1 })}
        />
        {resource.categoryFilter ? (
          <CategoryFilter value={filters.categoryId} onChange={(categoryId) => setFilters({ ...filters, categoryId, page: 1 })} />
        ) : null}
        <NativeSelect
          aria-label="Status filter"
          className="w-36"
          value={filters.active}
          onChange={(e) => setFilters({ ...filters, active: e.target.value as MasterFilters["active"], page: 1 })}
        >
          <option value="">All</option>
          <option value="1">Active</option>
          <option value="0">Inactive</option>
        </NativeSelect>
      </div>

      <FormAlert message={actionError} />
      {records.isPending ? <p className="text-sm text-muted-foreground">Loading…</p> : null}
      {records.isError ? <p className="text-sm text-destructive">{errorMessage(records.error)}</p> : null}

      {records.data ? (
        <>
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  {columns.map((c) => (
                    <TableHead key={c.name} className={numeric(c.type) ? "text-right" : undefined}>
                      {c.label}
                    </TableHead>
                  ))}
                  <TableHead>Status</TableHead>
                  <TableHead className="w-24 text-right">
                    <span className="sr-only">Actions</span>
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {records.data.items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={columns.length + 2} className="py-8 text-center text-muted-foreground">
                      {hasFilters ? `No ${resource.title.toLowerCase()} match the filters.` : `No ${resource.title.toLowerCase()} yet.`}
                    </TableCell>
                  </TableRow>
                ) : null}
                {records.data.items.map((record) =>
                  editing === record.id ? (
                    <TableRow key={record.id}>
                      <TableCell colSpan={columns.length + 2} className="whitespace-normal">
                        <MasterRecordForm resource={resource} record={record} onDone={() => setEditing(null)} />
                      </TableCell>
                    </TableRow>
                  ) : (
                    <TableRow key={record.id}>
                      {columns.map((c, i) => (
                        <TableCell
                          key={c.name}
                          className={i === 0 ? "font-medium" : numeric(c.type) ? "text-right tabular-nums" : "text-muted-foreground"}
                        >
                          {displayValue(c, record)}
                        </TableCell>
                      ))}
                      <TableCell>
                        {record.is_active ? <Badge variant="secondary">Active</Badge> : <Badge variant="outline">Inactive</Badge>}
                      </TableCell>
                      <TableCell>
                        <div className="flex justify-end gap-1">
                          {canUpdate ? (
                            <Button variant="ghost" size="icon-sm" aria-label={`Edit ${record.name}`} disabled={editing !== null} onClick={() => setEditing(record.id)}>
                              <Pencil aria-hidden />
                            </Button>
                          ) : null}
                          {canDelete ? (
                            <Button variant="ghost" size="icon-sm" aria-label={`Delete ${record.name}`} disabled={remove.isPending} onClick={() => onDelete(record)}>
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
          <Pager pagination={records.data.pagination} onPage={(page) => setFilters({ ...filters, page })} />
        </>
      ) : null}
    </>
  );
}
