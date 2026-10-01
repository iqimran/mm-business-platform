"use client";

import Link from "next/link";
import { useState } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { Pager } from "@/components/common/pager";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { usePermissions, useSession } from "@/features/auth/hooks";
import { documentTypeLabels, documentTypes, type ExpiryFilters } from "@/features/car-documents/api";
import { ExpiryBadge } from "@/features/car-documents/components/expiry-badge";
import { useExpiringDocuments, useExpirySummary } from "@/features/car-documents/hooks";
import { errorMessage } from "@/lib/form-errors";

const statusOptions: { value: ExpiryFilters["status"]; label: string }[] = [
  { value: "alerts", label: "Expired + expiring soon" },
  { value: "expired", label: "Expired" },
  { value: "expiring", label: "Expiring soon" },
  { value: "valid", label: "Valid" },
];

export default function DocumentExpiryPage() {
  const { can } = usePermissions();
  const { data: session } = useSession();
  const [filters, setFilters] = useState<ExpiryFilters>({ page: 1, status: "alerts", type: "", branchId: "" });
  const canView = can("car.document.view");
  const documents = useExpiringDocuments(filters);
  const summary = useExpirySummary(canView);
  const branches = session?.branches ?? [];

  if (!canView) return <Forbidden />;

  return (
    <>
      <PageHeader
        title="Document expiry"
        description={`Current car documents in your branches. "Expiring soon" means within ${summary.data?.alert_days ?? 30} days (change in Settings: car.document_alert_days).`}
      />

      {summary.data ? (
        <div className="grid gap-3 sm:grid-cols-2">
          <button
            type="button"
            onClick={() => setFilters({ ...filters, status: "expired", page: 1 })}
            className="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-left"
          >
            <div className="text-sm text-destructive">Expired</div>
            <div className="text-2xl font-semibold tabular-nums">{summary.data.expired}</div>
          </button>
          <button
            type="button"
            onClick={() => setFilters({ ...filters, status: "expiring", page: 1 })}
            className="rounded-lg border border-amber-500/30 bg-amber-500/5 p-4 text-left"
          >
            <div className="text-sm text-amber-700 dark:text-amber-400">Expiring within {summary.data.alert_days} days</div>
            <div className="text-2xl font-semibold tabular-nums">{summary.data.expiring}</div>
          </button>
        </div>
      ) : null}

      <div className="flex flex-wrap gap-2">
        <NativeSelect
          aria-label="Status filter"
          className="w-56"
          value={filters.status}
          onChange={(e) => setFilters({ ...filters, status: e.target.value as ExpiryFilters["status"], page: 1 })}
        >
          {statusOptions.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </NativeSelect>
        <NativeSelect
          aria-label="Document type filter"
          className="w-48"
          value={filters.type}
          onChange={(e) => setFilters({ ...filters, type: e.target.value as ExpiryFilters["type"], page: 1 })}
        >
          <option value="">All documents</option>
          {documentTypes.map((t) => (
            <option key={t} value={t}>
              {documentTypeLabels[t]}
            </option>
          ))}
        </NativeSelect>
        {branches.length > 1 ? (
          <NativeSelect
            aria-label="Branch filter"
            className="w-44"
            value={filters.branchId}
            onChange={(e) => setFilters({ ...filters, branchId: e.target.value, page: 1 })}
          >
            <option value="">All branches</option>
            {branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.code} — {b.name}
              </option>
            ))}
          </NativeSelect>
        ) : null}
      </div>

      {documents.isPending ? <p className="text-sm text-muted-foreground">Loading…</p> : null}
      {documents.isError ? <p className="text-sm text-destructive">{errorMessage(documents.error)}</p> : null}

      {documents.data ? (
        <>
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Car</TableHead>
                  <TableHead className="hidden md:table-cell">Branch</TableHead>
                  <TableHead>Document</TableHead>
                  <TableHead className="hidden sm:table-cell">Number</TableHead>
                  <TableHead>Expires</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {documents.data.items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                      Nothing here. All documents in this view are up to date.
                    </TableCell>
                  </TableRow>
                ) : null}
                {documents.data.items.map((doc) => (
                  <TableRow key={doc.id}>
                    <TableCell>
                      {doc.car ? (
                        <Link href={`/cars/${doc.car.id}`} className="font-medium underline-offset-4 hover:underline">
                          {doc.car.brand} {doc.car.model}
                        </Link>
                      ) : null}
                      <div className="text-xs text-muted-foreground">{doc.car?.registration_number ?? doc.car?.chassis_number}</div>
                    </TableCell>
                    <TableCell className="hidden md:table-cell">{doc.car?.branch?.code}</TableCell>
                    <TableCell>{doc.name}</TableCell>
                    <TableCell className="hidden sm:table-cell">{doc.document_number ?? "—"}</TableCell>
                    <TableCell className="tabular-nums">{doc.expiry_date}</TableCell>
                    <TableCell>
                      <ExpiryBadge status={doc.status} days={doc.days_remaining} />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          <Pager pagination={documents.data.pagination} onPage={(page) => setFilters({ ...filters, page })} />
        </>
      ) : null}
    </>
  );
}
