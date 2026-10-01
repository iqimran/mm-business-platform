import { cn } from "cn";
import type { DocumentStatus } from "../api";

const styles: Record<DocumentStatus, string> = {
  expired: "bg-destructive/10 text-destructive",
  expiring: "bg-amber-500/15 text-amber-700 dark:text-amber-400",
  valid: "bg-emerald-500/15 text-emerald-700 dark:text-emerald-400",
  superseded: "bg-muted text-muted-foreground",
};

export function expiryText(status: DocumentStatus, days: number): string {
  if (status === "superseded") return "Renewed";
  if (days < 0) return `Expired ${Math.abs(days)} day${days === -1 ? "" : "s"} ago`;
  if (days === 0) return "Expires today";
  return `${days} day${days === 1 ? "" : "s"} left`;
}

export function ExpiryBadge({ status, days }: { status: DocumentStatus; days: number }) {
  return (
    <span className={cn("inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap", styles[status])}>
      {expiryText(status, days)}
    </span>
  );
}
