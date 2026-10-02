/**
 * Restaurant workflows that also need lookup permissions (UI visibility only; the API enforces them):
 * a sale needs the menu, a booking needs halls and customers, an expense needs its categories.
 */
export const workflowPermissions = {
  newSale: ["restaurant.sale.create", "restaurant.menu.view"],
  newBooking: ["restaurant.booking.create", "restaurant.hall.view", "restaurant.customer.view"],
  newExpense: ["restaurant.expense.create", "restaurant.expense_category.view"],
} as const;

export type Workflow = keyof typeof workflowPermissions;

export function missingPermissions(can: (permission: string) => boolean, workflow: Workflow): string[] {
  return workflowPermissions[workflow].filter((permission) => !can(permission));
}

/** Message for a workflow the user cannot fully use. */
export function missingMessage(missing: string[]): string {
  return `You do not have all permissions needed here. Missing: ${missing.join(", ")}.`;
}
