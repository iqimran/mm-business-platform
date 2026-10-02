import { fetchAllPages } from "@/features/restaurant-common/lookups";
import { apiRequest } from "@/lib/api-client";
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
};

export type ExpensesPage = Paginated<RestaurantExpense> & { summary: { count: number; total: string } };

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
