/**
 * Configuration for the restaurant master-data screens (customers, suppliers, menu categories, menu items).
 * Validation mirrors the API rules; the API remains the source of truth.
 */
export type MasterField = {
  name: string;
  label: string;
  /** "money" = decimal string amount; "category" = menu category picker; "branch" = one of the user's branches. */
  type: "text" | "tel" | "textarea" | "number" | "money" | "category" | "branch";
  required?: boolean;
  /** Max length for text fields; max value for number fields. */
  max: number;
  /** Shown as a table column. */
  column?: boolean;
  /** Number fields: an empty value clears the field (null) instead of keeping the server default. */
  nullable?: boolean;
};

export type MasterResource = {
  /** API path under /api/v1. */
  path: "restaurant/customers" | "restaurant/suppliers" | "restaurant/menu-categories" | "restaurant/menu-items" | "restaurant/halls";
  title: string;
  singular: string;
  description: string;
  /** Permission prefix, e.g. "restaurant.customer". */
  permission: string;
  /** Records without delete (referenced later by sales/expenses) are deactivated instead. */
  deletable: boolean;
  /** Enables the category filter on the list. */
  categoryFilter?: boolean;
  fields: MasterField[];
};

export const customerResource: MasterResource = {
  path: "restaurant/customers",
  title: "Customers",
  singular: "customer",
  description: "Restaurant customers (food sales and hall bookings). Shared by all branches.",
  permission: "restaurant.customer",
  deletable: false,
  fields: [
    { name: "name", label: "Name", type: "text", required: true, max: 150, column: true },
    { name: "phone", label: "Phone", type: "tel", max: 30, column: true },
    { name: "address", label: "Address", type: "textarea", max: 1000 },
    { name: "notes", label: "Notes", type: "textarea", max: 5000 },
  ],
};

export const supplierResource: MasterResource = {
  path: "restaurant/suppliers",
  title: "Suppliers",
  singular: "supplier",
  description: "Restaurant suppliers. Shared by all branches.",
  permission: "restaurant.supplier",
  deletable: false,
  fields: [
    { name: "name", label: "Name", type: "text", required: true, max: 150, column: true },
    { name: "contact_person", label: "Contact person", type: "text", max: 150, column: true },
    { name: "phone", label: "Phone", type: "tel", max: 30, column: true },
    { name: "address", label: "Address", type: "textarea", max: 1000 },
    { name: "notes", label: "Notes", type: "textarea", max: 5000 },
  ],
};

export const menuCategoryResource: MasterResource = {
  path: "restaurant/menu-categories",
  title: "Menu categories",
  singular: "category",
  description: "Groups of the food menu. Shared by all branches.",
  permission: "restaurant.menu_category",
  deletable: true,
  fields: [
    { name: "name", label: "Name", type: "text", required: true, max: 100, column: true },
    { name: "description", label: "Description", type: "text", max: 255, column: true },
    { name: "sort_order", label: "Display order", type: "number", max: 9999, column: true },
  ],
};

export const menuItemResource: MasterResource = {
  path: "restaurant/menu-items",
  title: "Food menu",
  singular: "menu item",
  description: "Dishes and their selling price. Prices are the same in every branch.",
  permission: "restaurant.menu",
  deletable: true,
  categoryFilter: true,
  fields: [
    { name: "name", label: "Name", type: "text", required: true, max: 150, column: true },
    { name: "category_id", label: "Category", type: "category", required: true, max: 26, column: true },
    { name: "price", label: "Selling price", type: "money", required: true, max: 15, column: true },
    { name: "description", label: "Description", type: "textarea", max: 2000 },
  ],
};

export const hallResource: MasterResource = {
  path: "restaurant/halls",
  title: "Halls",
  singular: "hall",
  description: "Bookable halls of your branches. A hall with bookings can only be deactivated.",
  permission: "restaurant.hall",
  deletable: true,
  fields: [
    { name: "name", label: "Name", type: "text", required: true, max: 100, column: true },
    { name: "branch_id", label: "Branch", type: "branch", required: true, max: 26, column: true },
    { name: "capacity", label: "Capacity (guests)", type: "number", max: 100000, column: true, nullable: true },
    { name: "description", label: "Description", type: "textarea", max: 2000 },
  ],
};
