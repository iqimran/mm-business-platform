import { BarChart3, BookOpen, Building2, CalendarDays, Car, ChefHat, Contact, DatabaseBackup, FileClock, FolderTree, LayoutDashboard, Receipt, Settings, ShieldCheck, ShoppingBag, Tags, Truck, UserCog, Users, Wallet, Warehouse, type LucideIcon } from "lucide-react";

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
      { href: "/car-reports", label: "Reports", icon: BarChart3, permission: "car.report.view" },
      { href: "/car-documents", label: "Document expiry", icon: FileClock, permission: "car.document.view" },
      { href: "/car-dealers", label: "Dealers", icon: Truck, permission: "car.dealer.view" },
      { href: "/car-parties", label: "Parties", icon: Users, permission: "car.party.view" },
      { href: "/car-expense-types", label: "Expense types", icon: Receipt, permission: "car.expense_type.view" },
    ],
  },
  {
    title: "Restaurant",
    items: [
      { href: "/restaurant/sales", label: "Food sales", icon: ShoppingBag, permission: "restaurant.sale.view" },
      { href: "/restaurant/bookings", label: "Hall bookings", icon: CalendarDays, permission: "restaurant.booking.view" },
      { href: "/restaurant/expenses", label: "Expenses", icon: Wallet, permission: "restaurant.expense.view" },
      { href: "/restaurant/menu", label: "Food menu", icon: ChefHat, permission: "restaurant.menu.view" },
      { href: "/restaurant/menu-categories", label: "Menu categories", icon: Tags, permission: "restaurant.menu_category.view" },
      { href: "/restaurant/customers", label: "Customers", icon: Contact, permission: "restaurant.customer.view" },
      { href: "/restaurant/suppliers", label: "Suppliers", icon: BookOpen, permission: "restaurant.supplier.view" },
      { href: "/restaurant/halls", label: "Halls", icon: Warehouse, permission: "restaurant.hall.view" },
      { href: "/restaurant/expense-categories", label: "Expense categories", icon: FolderTree, permission: "restaurant.expense_category.view" },
    ],
  },
  {
    title: "Administration",
    items: [
      { href: "/users", label: "Users", icon: UserCog, permission: "user.view" },
      { href: "/branches", label: "Branches", icon: Building2, permission: "branch.view" },
      { href: "/roles", label: "Roles & Permissions", icon: ShieldCheck, permission: "role.view" },
      { href: "/settings", label: "Settings", icon: Settings, permission: "setting.view" },
      { href: "/backups", label: "Database backups", icon: DatabaseBackup, permission: "database.backup.view" },
    ],
  },
];

