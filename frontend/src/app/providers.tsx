"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useState, type ReactNode } from "react";
import { NotificationsProvider } from "@/components/common/notifications";
import { AppTitle } from "@/features/branding/hooks";

export function Providers({ children }: { children: ReactNode }) {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: { refetchOnWindowFocus: false },
        },
      }),
  );

  return (
    <QueryClientProvider client={queryClient}>
      <AppTitle />
      <NotificationsProvider>{children}</NotificationsProvider>
    </QueryClientProvider>
  );
}
