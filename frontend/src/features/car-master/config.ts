/**
 * Configuration for the shared car master-data screens (dealers, parties, expense types).
 * Validation mirrors the API rules; the API remains the source of truth.
 */
export type MasterField = {
  name: string;
  label: string;
  type: "text" | "email" | "tel" | "textarea";
  required?: boolean;
  max: number;
  /** Shown as a table column. */
  column?: boolean;
};

export type MasterResource = {
  /** API path under /api/v1 and page route. */
  path: "car-dealers" | "car-parties" | "car-expense-types";
  title: string;
  singular: string;
  description: string;
  /** Permission prefix, e.g. "car.dealer". */
  permission: string;
  fields: MasterField[];
};

export const dealerResource: MasterResource = {
  path: "car-dealers",
  title: "Dealers",
  singular: "dealer",
  description: "Suppliers cars are purchased from. Shared by all branches.",
  permission: "car.dealer",
  fields: [
    { name: "name", label: "Name", type: "text", required: true, max: 150, column: true },
    { name: "phone", label: "Phone", type: "tel", max: 30, column: true },
    { name: "email", label: "Email", type: "email", max: 255, column: true },
    { name: "address", label: "Address", type: "textarea", max: 1000 },
    { name: "notes", label: "Notes", type: "textarea", max: 5000 },
  ],
};

export const partyResource: MasterResource = {
  path: "car-parties",
  title: "Parties",
  singular: "party",
  description: "Customers cars are sold to. Shared by all branches.",
  permission: "car.party",
  fields: [
    { name: "name", label: "Name", type: "text", required: true, max: 150, column: true },
    { name: "phone", label: "Phone", type: "tel", max: 30, column: true },
    { name: "national_id", label: "National ID", type: "text", max: 50, column: true },
    { name: "email", label: "Email", type: "email", max: 255 },
    { name: "address", label: "Address", type: "textarea", max: 1000 },
    { name: "notes", label: "Notes", type: "textarea", max: 5000 },
  ],
};

export const expenseTypeResource: MasterResource = {
  path: "car-expense-types",
  title: "Expense types",
  singular: "expense type",
  description: "Categories used when recording car expenses.",
  permission: "car.expense_type",
  fields: [
    { name: "name", label: "Name", type: "text", required: true, max: 100, column: true },
    { name: "description", label: "Description", type: "text", max: 255, column: true },
  ],
};
