import { fetchAllPages } from "@/features/restaurant-common/lookups";
import type { PaymentInput, PaymentMethod, PaymentRecord, PaymentStatus } from "@/features/restaurant-common/payments";
import { apiOpenPdf, apiRequest } from "@/lib/api-client";
import type { Paginated } from "@/types/api";

export type RestaurantExpense = {
  id: string;
  branch?: { id: string; name: string; code: string };
  category?: { id: string; name: string };
  supplier?: { id: string; name: string } | null;
  expense_date: string;
  amount: string;
  description: string | null;
  reference: string | null;
  /** Supplier dues (server-computed): without a supplier the expense is always fully paid. */
  paid: string;
  due: string;
  payment_status: PaymentStatus;
  payments?: PaymentRecord[];
  recorded_by?: { id: string; name: string } | null;
  is_reversed: boolean;
  reversed_at: string | null;
  reversed_by?: { id: string; name: string } | null;
  reversal_reason: string | null;
};

export type ExpenseFilters = {
  page: number;
  search: string;
  branchId: string;
  categoryId: string;
  dateFrom: string;
  dateTo: string;
  state: "" | "active" | "reversed";
  /** "due" = unpaid or partially paid. */
  paymentStatus: "" | "due" | PaymentStatus;
  supplierId?: string;
};

export type ExpensesPage = Paginated<RestaurantExpense> & { summary: { count: number; total: string; paid: string; supplier_due: string } };

export type SupplierDue = {
  supplier: { id: string; name: string; phone: string | null };
  bills: number;
  due_bills: number;
  billed: string;
  paid: string;
  due: string;
  oldest_due_date: string | null;
};

export type SupplierDuesPage = Paginated<SupplierDue> & { totals: { suppliers: number; billed: string; paid: string; due: string } };

export type CategoryTotal = { category_id: string; category: string; total: string; count: number };

export type DailySummary = {
  date_from: string;
  date_to: string;
  days: { date: string; total: string; categories: CategoryTotal[] }[];
  categories: CategoryTotal[];
  total: string;
  count: number;
};

export type SummaryFilters = { dateFrom: string; dateTo: string; branchId: string; categoryId: string };

export type ExpenseInput = {
  branch_id: string;
  category_id: string;
  supplier_id: string | null;
  expense_date: string;
  amount: string;
  description: string | null;
  reference: string | null;
  /** Paid now (null = the full amount). Only a supplier bill can be partly paid. */
  paid_amount: string | null;
  payment_method: PaymentMethod;
  payment_reference: string | null;
};

export type CategoryOption = { id: string; name: string; is_active: boolean };

export function fetchExpenses(f: ExpenseFilters) {
  const params = new URLSearchParams({ page: String(f.page), per_page: "25" });
  if (f.search.trim()) params.set("search", f.search.trim());
  if (f.branchId) params.set("branch_id", f.branchId);
  if (f.categoryId) params.set("category_id", f.categoryId);
  if (f.dateFrom) params.set("date_from", f.dateFrom);
  if (f.dateTo) params.set("date_to", f.dateTo);
  if (f.state) params.set("state", f.state);
  if (f.paymentStatus) params.set("payment_status", f.paymentStatus);
  if (f.supplierId) params.set("supplier_id", f.supplierId);

  return apiRequest<ExpensesPage>(`/restaurant/expenses?${params}`);
}

export function fetchDailySummary(f: SummaryFilters) {
  const params = new URLSearchParams({ date_from: f.dateFrom, date_to: f.dateTo });
  if (f.branchId) params.set("branch_id", f.branchId);
  if (f.categoryId) params.set("category_id", f.categoryId);

  return apiRequest<DailySummary>(`/restaurant/expense-summary?${params}`);
}

export function createExpense(input: ExpenseInput) {
  return apiRequest<RestaurantExpense>("/restaurant/expenses", { method: "POST", body: input });
}

export function reverseExpense(id: string, reason: string) {
  return apiRequest<RestaurantExpense>(`/restaurant/expenses/${encodeURIComponent(id)}/reverse`, { method: "POST", body: { reason } });
}

/** Expense categories for dropdowns (a small catalog, all pages). */
export function fetchExpenseCategories(activeOnly: boolean) {
  return fetchAllPages<CategoryOption>("/restaurant/expense-categories", activeOnly);
}

export function fetchExpense(id: string) {
  return apiRequest<RestaurantExpense>(`/restaurant/expenses/${encodeURIComponent(id)}`);
}

/** Pays (part of) a supplier bill; the API rejects more than the remaining due. */
export function recordExpensePayment(id: string, input: PaymentInput) {
  return apiRequest<RestaurantExpense>(`/restaurant/expenses/${encodeURIComponent(id)}/payments`, { method: "POST", body: input });
}

export function reverseExpensePayment(id: string, paymentId: string, reason: string) {
  return apiRequest<RestaurantExpense>(
    `/restaurant/expenses/${encodeURIComponent(id)}/payments/${encodeURIComponent(paymentId)}/reverse`,
    { method: "POST", body: { reason } },
  );
}

/** Opens the A4 supplier payment voucher (PDF); reversed payments print as VOID. */
export function printExpenseVoucher(id: string, paymentId: string) {
  return apiOpenPdf(`/restaurant/expenses/${encodeURIComponent(id)}/payments/${encodeURIComponent(paymentId)}/voucher`);
}

export function fetchSupplierDues(params: { page: number; search: string; branchId: string; onlyDue: boolean }) {
  const query = new URLSearchParams({ page: String(params.page), per_page: "25", only_due: params.onlyDue ? "1" : "0" });
  if (params.search.trim()) query.set("search", params.search.trim());
  if (params.branchId) query.set("branch_id", params.branchId);
  return apiRequest<SupplierDuesPage>(`/restaurant/supplier-dues?${query}`);
}
