"use client";

import { Undo2 } from "lucide-react";
import { useState } from "react";
import { FormAlert } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { errorMessage } from "@/lib/form-errors";

/**
 * Reversal with a mandatory reason (financial records are never edited or deleted).
 */
export function ReverseButton({ label, onReverse }: { label: string; onReverse: (reason: string) => Promise<unknown> }) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string>();
  const [busy, setBusy] = useState(false);

  if (!open) {
    return (
      <Button variant="ghost" size="sm" onClick={() => setOpen(true)}>
        <Undo2 aria-hidden />
        Reverse
      </Button>
    );
  }

  const submit = async () => {
    if (reason.trim().length < 5) {
      setError("Give a reason of at least 5 characters.");
      return;
    }
    setBusy(true);
    setError(undefined);
    try {
      await onReverse(reason.trim());
      setOpen(false);
      setReason("");
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex w-full flex-col gap-2 rounded-lg border bg-muted/30 p-3">
      <Label htmlFor={`reverse-${label}`}>Reason for reversing {label}</Label>
      <Input id={`reverse-${label}`} value={reason} autoFocus maxLength={500} onChange={(e) => setReason(e.target.value)} />
      <FormAlert message={error} />
      <div className="flex justify-end gap-2">
        <Button variant="outline" size="sm" onClick={() => setOpen(false)} disabled={busy}>
          Cancel
        </Button>
        <Button variant="destructive" size="sm" onClick={submit} disabled={busy}>
          {busy ? "Reversing…" : "Confirm reversal"}
        </Button>
      </div>
    </div>
  );
}
