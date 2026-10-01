"use client";

import { Pencil, Plus } from "lucide-react";
import { useState } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { FormAlert, Forbidden, PageHeader } from "@/components/common/page-header";
import { Pager } from "@/components/common/pager";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { usePermissions } from "@/features/auth/hooks";
import type { Branch, BranchFilters } from "@/features/branches/api";
import { BranchForm } from "@/features/branches/components/branch-form";
import { useBranches, useSaveBranch } from "@/features/branches/hooks";
import { useDebouncedValue } from "@/hooks/use-debounced-value";
import { errorMessage } from "@/lib/form-errors";

/** null = closed, "" = new branch, otherwise the id being edited. */
type Editing = string | null;

export default function BranchesPage() {
  const { can } = usePermissions();
  const [filters, setFilters] = useState<BranchFilters>({ page: 1, search: "", active: "" });
  const [editing, setEditing] = useState<Editing>(null);
  const [error, setError] = useState<string>();
  const search = useDebouncedValue(filters.search);
  const branches = useBranches({ ...filters, search });
  const save = useSaveBranch();

  if (!can("branch.view")) return <Forbidden />;

  const toggleActive = (b: Branch) => {
    const action = b.is_active ? "Deactivate" : "Reactivate";
    const note = b.is_active ? " Its staff lose access to it, but all history is kept." : "";
    if (!window.confirm(`${action} branch ${b.code}?${note}`)) return;
    setError(undefined);
    save.mutate({ id: b.id, input: { is_active: !b.is_active } }, { onError: (e) => setError(errorMessage(e)) });
  };

  return (
    <>
      <PageHeader
        title="Branches"
        description="Branches never get deleted, so their history stays intact. Deactivate a branch to retire it."
        actions={
          can("branch.create") && editing === null ? (
            <Button onClick={() => setEditing("")}>
              <Plus aria-hidden />
              Add branch
            </Button>
          ) : null
        }
      />

      {editing === "" ? <BranchForm onDone={() => setEditing(null)} /> : null}

      <div className="flex flex-wrap gap-2">
        <Input
          type="search"
          placeholder="Search code or name…"
          aria-label="Search branches"
          className="max-w-xs"
          value={filters.search}
          onChange={(e) => setFilters({ ...filters, search: e.target.value, page: 1 })}
        />
        <NativeSelect
          aria-label="Status filter"
          className="w-36"
          value={filters.active}
          onChange={(e) => setFilters({ ...filters, active: e.target.value as BranchFilters["active"], page: 1 })}
        >
          <option value="">All</option>
          <option value="1">Active</option>
          <option value="0">Inactive</option>
        </NativeSelect>
      </div>

      <FormAlert message={error} />
      {branches.isPending ? <p className="text-sm text-muted-foreground">Loading…</p> : null}
      {branches.isError ? <p className="text-sm text-destructive">{errorMessage(branches.error)}</p> : null}

      {branches.data ? (
        <>
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Code</TableHead>
                  <TableHead>Name</TableHead>
                  <TableHead className="hidden md:table-cell">Phone</TableHead>
                  <TableHead className="hidden text-right sm:table-cell">Staff</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-44 text-right">
                    <span className="sr-only">Actions</span>
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {branches.data.items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                      No branches found.
                    </TableCell>
                  </TableRow>
                ) : null}
                {branches.data.items.map((b) =>
                  editing === b.id ? (
                    <TableRow key={b.id}>
                      <TableCell colSpan={6} className="whitespace-normal">
                        <BranchForm branch={b} onDone={() => setEditing(null)} />
                      </TableCell>
                    </TableRow>
                  ) : (
                    <TableRow key={b.id}>
                      <TableCell className="font-mono font-medium">{b.code}</TableCell>
                      <TableCell>
                        {b.name}
                        {b.address ? <div className="text-xs text-muted-foreground">{b.address}</div> : null}
                      </TableCell>
                      <TableCell className="hidden md:table-cell">{b.phone ?? "—"}</TableCell>
                      <TableCell className="hidden text-right tabular-nums sm:table-cell">{b.users_count ?? "—"}</TableCell>
                      <TableCell>{b.is_active ? <Badge variant="secondary">Active</Badge> : <Badge variant="outline">Inactive</Badge>}</TableCell>
                      <TableCell>
                        {can("branch.update") ? (
                          <div className="flex justify-end gap-1">
                            <Button variant="ghost" size="icon-sm" aria-label={`Edit ${b.code}`} disabled={editing !== null} onClick={() => setEditing(b.id)}>
                              <Pencil aria-hidden />
                            </Button>
                            <Button variant="outline" size="sm" disabled={save.isPending} onClick={() => toggleActive(b)}>
                              {b.is_active ? "Deactivate" : "Reactivate"}
                            </Button>
                          </div>
                        ) : null}
                      </TableCell>
                    </TableRow>
                  ),
                )}
              </TableBody>
            </Table>
          </div>
          <Pager pagination={branches.data.pagination} onPage={(page) => setFilters({ ...filters, page })} />
        </>
      ) : null}
    </>
  );
}
