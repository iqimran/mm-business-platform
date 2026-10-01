"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  fetchExpenses,
  fetchPurchase,
  recordExpense,
  recordPurchase,
  reverseExpense,
  reversePurchase,
  type ExpenseInput,
  type PurchaseInput,
} from "./api";

const financeKey = (carId: string) => ["cars", "finance", carId] as const;

export function usePurchase(carId: string, enabled: boolean) {
  return useQuery({ queryKey: [...financeKey(carId), "purchase"], queryFn: () => fetchPurchase(carId), enabled });
}

export function useExpenses(carId: string, enabled: boolean) {
  return useQuery({ queryKey: [...financeKey(carId), "expenses"], queryFn: () => fetchExpenses(carId), enabled });
}

/** Any financial change refreshes both sections (shared cost summary) and the car (dealer may change). */
function useFinanceMutation<TArgs>(mutationFn: (args: TArgs) => Promise<unknown>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["cars"] }),
  });
}

export function useRecordPurchase(carId: string) {
  return useFinanceMutation((input: PurchaseInput) => recordPurchase(carId, input));
}

export function useReversePurchase(carId: string) {
  return useFinanceMutation(({ id, reason }: { id: string; reason: string }) => reversePurchase(carId, id, reason));
}

export function useRecordExpense(carId: string) {
  return useFinanceMutation((input: ExpenseInput) => recordExpense(carId, input));
}

export function useReverseExpense(carId: string) {
  return useFinanceMutation(({ id, reason }: { id: string; reason: string }) => reverseExpense(carId, id, reason));
}
