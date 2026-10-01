"use client";

import { Plus } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { Pager } from "@/components/common/pager";
import { Badge } from "@/components/ui/badge";
import { buttonVariants } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { usePermissions } from "@/features/auth/hooks";
import type { UserFilters } from "@/features/users/api";
import { useAssignmentOptions, useUsers } from "@/features/users/hooks";
import { useDebouncedValue } from "@/hooks/use-debounced-value";
import { errorMessage } from "@/lib/form-errors";

export default function UsersPage() {
  const { can } = usePermissions();
  const [filters, setFilters] = useState<UserFilters>({ page: 1, search: "", active: "", roleId: "", branchId: "" });
  const search = useDebouncedValue(filters.search);
  const users = useUsers({ ...filters, search });
  const options = useAssignmentOptions();

  if (!can("user.view")) return <Forbidden />;

  const set = (patch: Partial<UserFilters>) => setFilters({ ...filters, ...patch, page: 1 });

  return (
    <>
      <PageHeader
        title="Users"
        description="Staff accounts. Access comes from roles (what they can do) and branches (where)."
        actions={
          can("user.create") ? (
            <Link href="/users/new" className={buttonVariants()}>
              <Plus aria-hidden />
              New user
            </Link>
          ) : null
        }
      />

      <div className="flex flex-wrap gap-2">
        <Input type="search" placeholder="Search name or email…" aria-label="Search users" className="max-w-xs" value={filters.search} onChange={(e) => set({ search: e.target.value })} />
        <NativeSelect aria-label="Status" className="w-36" value={filters.active} onChange={(e) => set({ active: e.target.value as UserFilters["active"] })}>
          <option value="">All</option>
          <option value="1">Active</option>
          <option value="0">Inactive</option>
        </NativeSelect>
        {options.roles ? (
          <NativeSelect aria-label="Role" className="w-44" value={filters.roleId} onChange={(e) => set({ roleId: e.target.value })}>
            <option value="">All roles</option>
            {options.roles.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </NativeSelect>
        ) : null}
        {options.branches.length > 1 ? (
          <NativeSelect aria-label="Branch" className="w-44" value={filters.branchId} onChange={(e) => set({ branchId: e.target.value })}>
            <option value="">All branches</option>
            {options.branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.label}
              </option>
            ))}
          </NativeSelect>
        ) : null}
      </div>

      {users.isPending ? <p className="text-sm text-muted-foreground">Loading…</p> : null}
      {users.isError ? <p className="text-sm text-destructive">{errorMessage(users.error)}</p> : null}

      {users.data ? (
        <>
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>User</TableHead>
                  <TableHead>Roles</TableHead>
                  <TableHead className="hidden md:table-cell">Branches</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {users.data.items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={4} className="py-8 text-center text-muted-foreground">
                      No users found.
                    </TableCell>
                  </TableRow>
                ) : null}
                {users.data.items.map((u) => (
                  <TableRow key={u.id}>
                    <TableCell>
                      <Link href={`/users/${u.id}`} className="font-medium underline-offset-4 hover:underline">
                        {u.name}
                      </Link>
                      <div className="text-xs text-muted-foreground">{u.email}</div>
                    </TableCell>
                    <TableCell className="whitespace-normal">
                      <div className="flex flex-wrap gap-1">
                        {(u.roles ?? []).length === 0 ? <span className="text-xs text-muted-foreground">No roles</span> : null}
                        {(u.roles ?? []).map((r) => (
                          <Badge key={r.id} variant="secondary">
                            {r.name}
                          </Badge>
                        ))}
                      </div>
                    </TableCell>
                    <TableCell className="hidden whitespace-normal md:table-cell">
                      {(u.branches ?? []).map((b) => b.code).join(", ") || <span className="text-xs text-muted-foreground">None</span>}
                    </TableCell>
                    <TableCell>{u.is_active ? <Badge variant="secondary">Active</Badge> : <Badge variant="outline">Inactive</Badge>}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          <Pager pagination={users.data.pagination} onPage={(page) => setFilters({ ...filters, page })} />
        </>
      ) : null}
    </>
  );
}
