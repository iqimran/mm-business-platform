import { apiDownload, apiRequest } from "@/lib/api-client";
import type { Paginated } from "@/types/api";

export type BackupStatus = "pending" | "running" | "completed" | "failed" | "deleted";

export type DatabaseBackup = {
  id: string;
  type: "automatic" | "manual";
  status: BackupStatus;
  filename: string | null;
  size_bytes: number | null;
  checksum_sha256: string | null;
  error_message: string | null;
  requested_by?: { id: string; name: string } | null;
  downloadable: boolean;
  created_at: string;
  started_at: string | null;
  completed_at: string | null;
  deleted_at: string | null;
};

export function fetchBackups(page: number) {
  return apiRequest<Paginated<DatabaseBackup>>(`/database-backups?page=${page}&per_page=25`);
}

/** Queues a manual backup; the returned record is "pending" until the queue worker finishes it. */
export function createBackup() {
  return apiRequest<DatabaseBackup>("/database-backups", { method: "POST" });
}

export function downloadBackup(backup: DatabaseBackup) {
  return apiDownload(`/database-backups/${encodeURIComponent(backup.id)}/download`, backup.filename ?? "database-backup.sql");
}
