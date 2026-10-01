"use client";

import { DatabaseBackup as DatabaseIcon, Download } from "lucide-react";
import { useState } from "react";
import { FormAlert, Forbidden, PageHeader } from "@/components/common/page-header";
import { Pager } from "@/components/common/pager";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { usePermissions } from "@/features/auth/hooks";
import { downloadBackup, type BackupStatus, type DatabaseBackup } from "@/features/backups/api";
import { formatBytes, formatDateTime } from "@/features/backups/format";
import { useBackups, useCreateBackup } from "@/features/backups/hooks";
import { errorMessage } from "@/lib/form-errors";

const statusStyles: Record<BackupStatus, { label: string; className: string }> = {
  pending: { label: "Queued", className: "bg-muted text-muted-foreground" },
  running: { label: "Running…", className: "bg-sky-500/15 text-sky-700 dark:text-sky-400" },
  completed: { label: "Completed", className: "bg-emerald-500/15 text-emerald-700 dark:text-emerald-400" },
  failed: { label: "Failed", className: "bg-destructive/10 text-destructive" },
  deleted: { label: "Expired (deleted)", className: "bg-muted text-muted-foreground" },
};

function StatusBadge({ status }: { status: BackupStatus }) {
  const s = statusStyles[status];
  return <span className={`inline-flex rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap ${s.className}`}>{s.label}</span>;
}

export default function BackupsPage() {
  const { can } = usePermissions();
  const [page, setPage] = useState(1);
  const backups = useBackups(page);
  const create = useCreateBackup();
  const [message, setMessage] = useState<{ ok: boolean; text: string }>();
  const [downloading, setDownloading] = useState<string | null>(null);

  if (!can("database.backup.view")) return <Forbidden />;

  const inProgress = backups.data?.items.some((b) => b.status === "pending" || b.status === "running") ?? false;

  const start = () => {
    setMessage(undefined);
    create.mutate(undefined, {
      onSuccess: () => setMessage({ ok: true, text: "Backup started. It runs in the background; this list updates automatically." }),
      onError: (e) => setMessage({ ok: false, text: errorMessage(e) }),
    });
  };

  const download = async (b: DatabaseBackup) => {
    setDownloading(b.id);
    setMessage(undefined);
    try {
      await downloadBackup(b);
    } catch (e) {
      setMessage({ ok: false, text: errorMessage(e) });
    } finally {
      setDownloading(null);
    }
  };

  return (
    <>
      <PageHeader
        title="Database backups"
        description="Full PostgreSQL backups (.sql). An automatic backup runs every day; expired backups are removed by the retention policy."
        actions={
          can("database.backup.create") ? (
            <Button onClick={start} disabled={create.isPending || inProgress}>
              <DatabaseIcon aria-hidden />
              {create.isPending ? "Starting…" : inProgress ? "Backup in progress…" : "Back up now"}
            </Button>
          ) : null
        }
      />

      <p className="rounded-md border border-amber-500/30 bg-amber-500/5 px-3 py-2 text-sm text-amber-800 dark:text-amber-300">
        Backup files contain the entire business database. Store downloads securely and never share them over email or chat.
      </p>

      {message ? (
        message.ok ? (
          <p role="status" className="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-800 dark:text-emerald-300">
            {message.text}
          </p>
        ) : (
          <FormAlert message={message.text} />
        )
      ) : null}

      {backups.isPending ? <p className="text-sm text-muted-foreground">Loading backups…</p> : null}
      {backups.isError ? <p className="text-sm text-destructive">{errorMessage(backups.error)}</p> : null}

      {backups.data ? (
        backups.data.items.length === 0 ? (
          <div className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
            No backups yet. The first automatic backup runs tonight{can("database.backup.create") ? ", or use “Back up now”." : "."}
          </div>
        ) : (
          <>
            <div className="rounded-lg border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Created</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="hidden md:table-cell">File</TableHead>
                    <TableHead className="text-right">Size</TableHead>
                    <TableHead className="w-28 text-right">
                      <span className="sr-only">Actions</span>
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {backups.data.items.map((b) => (
                    <TableRow key={b.id}>
                      <TableCell className="whitespace-nowrap">
                        {formatDateTime(b.created_at)}
                        {b.requested_by ? <div className="text-xs text-muted-foreground">by {b.requested_by.name}</div> : null}
                      </TableCell>
                      <TableCell>
                        <Badge variant={b.type === "manual" ? "secondary" : "outline"}>{b.type === "manual" ? "Manual" : "Automatic"}</Badge>
                      </TableCell>
                      <TableCell className="whitespace-normal">
                        <StatusBadge status={b.status} />
                        {b.status === "failed" && b.error_message ? <div className="mt-1 max-w-sm text-xs text-destructive">{b.error_message}</div> : null}
                      </TableCell>
                      <TableCell className="hidden font-mono text-xs md:table-cell">{b.filename ?? "—"}</TableCell>
                      <TableCell className="text-right tabular-nums">{formatBytes(b.size_bytes)}</TableCell>
                      <TableCell className="text-right">
                        {b.downloadable && can("database.backup.download") ? (
                          <Button variant="outline" size="sm" disabled={downloading !== null} onClick={() => download(b)}>
                            <Download aria-hidden />
                            {downloading === b.id ? "…" : "Download"}
                          </Button>
                        ) : null}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
            <Pager pagination={backups.data.pagination} onPage={setPage} />
          </>
        )
      ) : null}
    </>
  );
}
