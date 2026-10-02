"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import type { PaymentInput } from "@/features/restaurant-common/payments";
import {
  createExpense,
  fetchDailySummary,
  fetchExpense,
  fetchExpenseCategories,
  fetchExpenses,
  fetchSupplierDues,
  recordExpensePayment,
  reverseExpense,
  reverseExpensePayment,
  type ExpenseFilters,
  type ExpenseInput,
  type SummaryFilters,
} from "./api";
import { rangeDays } from "./schemas";

const expensesKey = ["restaurant-expenses"] as const;
const supplierDuesKey = ["restaurant-supplier-dues"] as const;

export function useExpenses(filters: ExpenseFilters, enabled = true) {
  return useQuery({ queryKey: [...expensesKey, "list", filters], queryFn: () => fetchExpenses(filters), placeholderData: keepPreviousData, enabled });
}

export function useExpense(id: string) {
  return useQuery({ queryKey: [...expensesKey, "detail", id], queryFn: () => fetchExpense(id) });
}

export function useDailySummary(filters: SummaryFilters) {
  const days = rangeDays(filters.dateFrom, filters.dateTo);
  return useQuery({
    queryKey: [...expensesKey, "summary", filters],
    queryFn: () => fetchDailySummary(filters),
    enabled: days !== null && days <= 366,
    placeholderData: keepPreviousData,
  });
}

export function useSupplierDues(params: Parameters<typeof fetchSupplierDues>[0]) {
  return useQuery({ queryKey: [...supplierDuesKey, params], queryFn: () => fetchSupplierDues(params), placeholderData: keepPreviousData });
}

/** Disabled (no request) when the user may not view expense categories. */
export function useExpenseCategories(activeOnly: boolean, enabled = true) {
  return useQuery({ queryKey: ["restaurant/expense-categories", "options", activeOnly], queryFn: () => fetchExpenseCategories(activeOnly), enabled, staleTime: 60_000 });
}

/** Any expense or supplier payment change refreshes lists, details and supplier dues. */
function useExpenseMutation<TArgs, TResult>(mutationFn: (args: TArgs) => Promise<TResult>) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: expensesKey });
      queryClient.invalidateQueries({ queryKey: supplierDuesKey });
    },
  });
}

export function useCreateExpense() {
  return useExpenseMutation((input: ExpenseInput) => createExpense(input));
}

export function useReverseExpense() {
  return useExpenseMutation(({ id, reason }: { id: string; reason: string }) => reverseExpense(id, reason));
}

export function useRecordExpensePayment(id: string) {
  return useExpenseMutation((input: PaymentInput) => recordExpensePayment(id, input));
}

export function useReverseExpensePayment(id: string) {
  return useExpenseMutation(({ paymentId, reason }: { paymentId: string; reason: string }) => reverseExpensePayment(id, paymentId, reason));
}
