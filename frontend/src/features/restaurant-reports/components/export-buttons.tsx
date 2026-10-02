"use client";

import { Download } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { errorMessage } from "@/lib/form-errors";
import { exportReport, type ExportFormat, type ReportName } from "../api";

/** Excel / PDF export of the current report view (same filters and sorting as on screen). */
export function ExportButtons({ report, query }: { report: ReportName | "summary"; query: Parameters<typeof exportReport>[1] }) {
  const [busy, setBusy] = useState<ExportFormat | null>(null);
  const [error, setError] = useState<string>();

  const run = async (format: ExportFormat) => {
    setBusy(format);
    setError(undefined);
    try {
      await exportReport(report, query, format);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="flex flex-col items-end gap-1">
      <div className="flex gap-2">
        {(["xlsx", "pdf"] as ExportFormat[]).map((format) => (
          <Button key={format} type="button" size="sm" variant="outline" disabled={busy !== null} onClick={() => run(format)}>
            <Download aria-hidden />
            {busy === format ? "Exporting…" : format === "xlsx" ? "Excel" : "PDF"}
          </Button>
        ))}
      </div>
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
    </div>
  );
}
