import { apiRequest } from "@/lib/api-client";

/** Amounts are decimal strings from the API (e.g. "700000.00"); never parsed to floats for math. */
export type FinancialRecord = {
  id: string;
  amount: string;
  reference: string | null;
  recorded_by?: { id: string; name: string } | null;
  created_at: string;
  is_reversed: boolean;
  reversed_at: string | null;
  reversed_by?: { id: string; name: string } | null;
  reversal_reason: string | null;
};

export type Purchase = FinancialRecord & {
  dealer?: { id: string; name: string };
  purchase_date: string;
  notes: string | null;
};

export type Expense = FinancialRecord & {
  expense_type?: { id: string; name: string };
  expense_date: string;
  description: string | null;
};

export type CarCosts = {
  purchase_cost: string;
  expenses_total: string;
  total_investment: string;
};

export type PurchaseInput = {
  dealer_id: string;
  purchase_date: string;
  amount: string;
  reference: string | null;
  notes: string | null;
};

export type ExpenseInput = {
  expense_type_id: string;
  expense_date: string;
  amount: string;
  description: string | null;
  reference: string | null;
};

const car = (id: string) => `/cars/${encodeURIComponent(id)}`;

export function fetchPurchase(carId: string) {
  return apiRequest<{ active: Purchase | null; history: Purchase[]; costs: CarCosts }>(`${car(carId)}/purchase`);
}

export function recordPurchase(carId: string, input: PurchaseInput) {
  return apiRequest<Purchase>(`${car(carId)}/purchases`, { method: "POST", body: input });
}

export function reversePurchase(carId: string, purchaseId: string, reason: string) {
  return apiRequest<Purchase>(`${car(carId)}/purchases/${encodeURIComponent(purchaseId)}/reverse`, { method: "POST", body: { reason } });
}

export function fetchExpenses(carId: string) {
  return apiRequest<{ items: Expense[]; costs: CarCosts }>(`${car(carId)}/expenses`);
}

export function recordExpense(carId: string, input: ExpenseInput) {
  return apiRequest<Expense>(`${car(carId)}/expenses`, { method: "POST", body: input });
}

export function reverseExpense(carId: string, expenseId: string, reason: string) {
  return apiRequest<Expense>(`${car(carId)}/expenses/${encodeURIComponent(expenseId)}/reverse`, { method: "POST", body: { reason } });
}
