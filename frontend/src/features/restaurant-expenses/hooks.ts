"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { createExpense, fetchDailySummary, fetchExpenseCategories, fetchExpenses, reverseExpense, type ExpenseFilters, type ExpenseInput, type SummaryFilters } from "./api";
import { rangeDays } from "./schemas";

const expensesKey = ["restaurant-expenses"] as const;

export function useExpenses(filters: ExpenseFilters) {
  return useQuery({ queryKey: [...expensesKey, "list", filters], queryFn: () => fetchExpenses(filters), placeholderData: keepPreviousData });
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

export function useExpenseCategories(activeOnly: boolean) {
  return useQuery({ queryKey: ["restaurant/expense-categories", "options", activeOnly], queryFn: () => fetchExpenseCategories(activeOnly), staleTime: 60_000 });
}

function useExpenseMutation<TArgs>(mutationFn: (args: TArgs) => Promise<unknown>) {
  const queryClient = useQueryClient();
  return useMutation({ mutationFn, onSuccess: () => queryClient.invalidateQueries({ queryKey: expensesKey }) });
}

export function useCreateExpense() {
  return useExpenseMutation((input: ExpenseInput) => createExpense(input));
}

export function useReverseExpense() {
  return useExpenseMutation(({ id, reason }: { id: string; reason: string }) => reverseExpense(id, reason));
}
