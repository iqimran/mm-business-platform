"use client";

import { CheckCircle2, X } from "lucide-react";
import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from "react";

type Notice = { id: number; message: string };

const NotifyContext = createContext<(message: string) => void>(() => {});

/** How long a success notice stays visible. */
const VISIBLE_MS = 4000;

/**
 * Lightweight success notices ("Sale recorded."), announced politely to screen readers.
 * Errors are not shown here: forms keep showing them next to the fields they belong to.
 */
export function NotificationsProvider({ children }: { children: ReactNode }) {
  const [notices, setNotices] = useState<Notice[]>([]);
  const nextId = useRef(0);

  const dismiss = useCallback((id: number) => setNotices((current) => current.filter((n) => n.id !== id)), []);
  const notify = useCallback(
    (message: string) => {
      const id = ++nextId.current;
      setNotices((current) => [...current.slice(-2), { id, message }]);
      window.setTimeout(() => dismiss(id), VISIBLE_MS);
    },
    [dismiss],
  );
  const value = useMemo(() => notify, [notify]);

  return (
    <NotifyContext.Provider value={value}>
      {children}
      <div role="status" aria-live="polite" className="pointer-events-none fixed right-4 bottom-4 z-50 flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2">
        {notices.map((n) => (
          <div key={n.id} className="pointer-events-auto flex items-start gap-2 rounded-lg border bg-background p-3 text-sm shadow-md">
            <CheckCircle2 aria-hidden className="mt-0.5 size-4 shrink-0 text-emerald-600" />
            <span className="flex-1">{n.message}</span>
            <button type="button" aria-label="Dismiss" className="text-muted-foreground hover:text-foreground" onClick={() => dismiss(n.id)}>
              <X aria-hidden className="size-4" />
            </button>
          </div>
        ))}
      </div>
    </NotifyContext.Provider>
  );
}

/** Shows a short success message. */
export function useNotify() {
  return useContext(NotifyContext);
}
