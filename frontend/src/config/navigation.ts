import { LayoutDashboard, Settings, ShieldCheck, type LucideIcon } from "lucide-react";

export type NavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
  /** Shown only when the user holds this permission (UI hint; the API enforces access). */
  permission?: string;
};

export const navigation: NavItem[] = [
  { href: "/dashboard", label: "Dashboard", icon: LayoutDashboard },
  { href: "/roles", label: "Roles & Permissions", icon: ShieldCheck, permission: "role.view" },
  { href: "/settings", label: "Settings", icon: Settings, permission: "setting.view" },
];
