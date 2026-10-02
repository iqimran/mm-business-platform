"use client";

import { LogOut } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import type { ReactNode } from "react";
import { cn } from "cn";
import { Button } from "@/components/ui/button";
import { navigation } from "@/config/navigation";
import type { Session } from "@/features/auth/api";
import { useLogout } from "@/features/auth/hooks";
import { useAppName } from "@/features/branding/hooks";

export function AppShell({ session, children }: { session: Session; children: ReactNode }) {
  const pathname = usePathname();
  const logout = useLogout();
  const appName = useAppName();
  const granted = new Set(session.permissions);
  const sections = navigation
    .map((section) => ({ ...section, items: section.items.filter((item) => !item.permission || granted.has(item.permission)) }))
    .filter((section) => section.items.length > 0);
  const items = sections.flatMap((section) => section.items);

  const isActive = (href: string) => pathname === href || pathname.startsWith(`${href}/`);

  return (
    <div className="flex min-h-svh w-full">
      <aside className="hidden w-60 shrink-0 flex-col border-r bg-muted/30 md:flex">
        <div className="flex h-14 items-center border-b px-4 font-semibold leading-tight" title={appName}>
          <span className="line-clamp-2">{appName}</span>
        </div>
        <nav aria-label="Main" className="flex flex-1 flex-col gap-4 p-2">
          {sections.map((section, index) => (
            <div key={section.title ?? index} className="flex flex-col gap-1">
              {section.title ? (
                <div className="px-3 pb-1 text-xs font-bold uppercase tracking-wide">{section.title}</div>
              ) : null}
              {section.items.map((item) => (
                <Link
                  key={item.href}
                  href={item.href}
                  aria-current={isActive(item.href) ? "page" : undefined}
                  className={cn(
                    "flex items-center gap-2 rounded-md px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground",
                    isActive(item.href) && "bg-muted font-medium text-foreground",
                  )}
                >
                  <item.icon className="size-4" aria-hidden />
                  {item.label}
                </Link>
              ))}
            </div>
          ))}
        </nav>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-14 items-center justify-between gap-4 border-b px-4">
          {/* Compact navigation for small screens */}
          <nav aria-label="Main" className="flex gap-1 overflow-x-auto md:hidden">
            {items.map((item) => (
              <Link
                key={item.href}
                href={item.href}
                aria-label={item.label}
                className={cn("rounded-md p-2 text-muted-foreground", isActive(item.href) && "bg-muted text-foreground")}
              >
                <item.icon className="size-4" aria-hidden />
              </Link>
            ))}
          </nav>
          <div className="ml-auto flex items-center gap-3">
            <div className="hidden text-right text-sm sm:block">
              <div className="font-medium leading-tight">{session.user.name}</div>
              <div className="text-xs text-muted-foreground">{session.user.email}</div>
            </div>
            <Button variant="outline" size="sm" onClick={() => logout.mutate()} disabled={logout.isPending}>
              <LogOut aria-hidden />
              {logout.isPending ? "Signing out…" : "Sign out"}
            </Button>
          </div>
        </header>
        <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">{children}</main>
      </div>
    </div>
  );
}
