import { Car, FileClock, LayoutDashboard, Receipt, Settings, ShieldCheck, Truck, Users, type LucideIcon } from "lucide-react";

export type NavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
  /** Shown only when the user holds this permission (UI hint; the API enforces access). */
  permission?: string;
};

export type NavSection = {
  title?: string;
  items: NavItem[];
};

export const navigation: NavSection[] = [
  {
    items: [{ href: "/dashboard", label: "Dashboard", icon: LayoutDashboard }],
  },
  {
    title: "Car Business",
    items: [
      { href: "/cars", label: "Cars", icon: Car, permission: "car.view" },
      { href: "/car-documents", label: "Document expiry", icon: FileClock, permission: "car.document.view" },
      { href: "/car-dealers", label: "Dealers", icon: Truck, permission: "car.dealer.view" },
      { href: "/car-parties", label: "Parties", icon: Users, permission: "car.party.view" },
      { href: "/car-expense-types", label: "Expense types", icon: Receipt, permission: "car.expense_type.view" },
    ],
  },
  {
    title: "Administration",
    items: [
      { href: "/roles", label: "Roles & Permissions", icon: ShieldCheck, permission: "role.view" },
      { href: "/settings", label: "Settings", icon: Settings, permission: "setting.view" },
    ],
  },
];

