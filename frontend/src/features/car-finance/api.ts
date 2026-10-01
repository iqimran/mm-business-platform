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

// ---- Sale, party (customer) payments, dealer payments, lifecycle ----

export const paymentMethods = ["cash", "bank_transfer", "cheque", "mobile_banking", "other"] as const;
export type PaymentMethod = (typeof paymentMethods)[number];

export const paymentMethodLabels: Record<PaymentMethod, string> = {
  cash: "Cash",
  bank_transfer: "Bank transfer",
  cheque: "Cheque",
  mobile_banking: "Mobile banking",
  other: "Other",
};

export type Sale = FinancialRecord & {
  party?: { id: string; name: string; phone: string | null };
  sale_date: string;
  notes: string | null;
};

export type Payment = FinancialRecord & {
  payment_date: string;
  method: PaymentMethod;
  notes: string | null;
};

/** Party Due = sale amount - payments received. */
export type PartyPosition = { amount: string; received: string; due: string };

/** Dealer Payable = purchase amount - payments made. */
export type DealerPosition = { purchase_amount: string; paid: string; payable: string };

export type SaleView = {
  status: string;
  active: Sale | null;
  history: Sale[];
  payments: Payment[];
  party: PartyPosition | null;
  /** Null when not sold or when the user may not see costs. */
  profit: string | null;
};

export type SaleInput = { party_id: string; sale_date: string; amount: string; reference: string | null; notes: string | null };

export type PaymentInput = { payment_date: string; amount: string; method: PaymentMethod; reference: string | null; notes: string | null };

export function fetchSale(carId: string) {
  return apiRequest<SaleView>(`${car(carId)}/sale`);
}

export function recordSale(carId: string, input: SaleInput) {
  return apiRequest<Sale>(`${car(carId)}/sales`, { method: "POST", body: input });
}

export function reverseSale(carId: string, saleId: string, reason: string) {
  return apiRequest<Sale>(`${car(carId)}/sales/${encodeURIComponent(saleId)}/reverse`, { method: "POST", body: { reason } });
}

export function recordPartyPayment(carId: string, input: PaymentInput) {
  return apiRequest<{ payment: Payment; party: PartyPosition }>(`${car(carId)}/party-payments`, { method: "POST", body: input });
}

export function reversePartyPayment(carId: string, paymentId: string, reason: string) {
  return apiRequest<{ payment: Payment; party: PartyPosition }>(`${car(carId)}/party-payments/${encodeURIComponent(paymentId)}/reverse`, {
    method: "POST",
    body: { reason },
  });
}

export function fetchDealerPayments(carId: string) {
  return apiRequest<{ items: Payment[]; dealer: DealerPosition | null }>(`${car(carId)}/dealer-payments`);
}

export function recordDealerPayment(carId: string, input: PaymentInput) {
  return apiRequest<{ payment: Payment; dealer: DealerPosition }>(`${car(carId)}/dealer-payments`, { method: "POST", body: input });
}

export function reverseDealerPayment(carId: string, paymentId: string, reason: string) {
  return apiRequest<{ payment: Payment; dealer: DealerPosition }>(`${car(carId)}/dealer-payments/${encodeURIComponent(paymentId)}/reverse`, {
    method: "POST",
    body: { reason },
  });
}

export function changeCarStatus(carId: string, status: string, reason?: string) {
  return apiRequest<{ status: string; next_statuses: string[] }>(`${car(carId)}/status`, {
    method: "POST",
    body: reason ? { status, reason } : { status },
  });
}
