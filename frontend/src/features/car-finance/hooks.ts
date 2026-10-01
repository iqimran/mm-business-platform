"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  changeCarStatus,
  fetchDealerPayments,
  fetchExpenses,
  fetchPurchase,
  fetchSale,
  recordDealerPayment,
  recordExpense,
  recordPartyPayment,
  recordPurchase,
  recordSale,
  reverseDealerPayment,
  reverseExpense,
  reversePartyPayment,
  reversePurchase,
  reverseSale,
  type ExpenseInput,
  type PaymentInput,
  type PurchaseInput,
  type SaleInput,
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

export function useSale(carId: string, enabled: boolean) {
  return useQuery({ queryKey: [...financeKey(carId), "sale"], queryFn: () => fetchSale(carId), enabled });
}

export function useDealerPayments(carId: string, enabled: boolean) {
  return useQuery({ queryKey: [...financeKey(carId), "dealer-payments"], queryFn: () => fetchDealerPayments(carId), enabled });
}

export function useRecordSale(carId: string) {
  return useFinanceMutation((input: SaleInput) => recordSale(carId, input));
}

export function useReverseSale(carId: string) {
  return useFinanceMutation(({ id, reason }: { id: string; reason: string }) => reverseSale(carId, id, reason));
}

export function useRecordPartyPayment(carId: string) {
  return useFinanceMutation((input: PaymentInput) => recordPartyPayment(carId, input));
}

export function useReversePartyPayment(carId: string) {
  return useFinanceMutation(({ id, reason }: { id: string; reason: string }) => reversePartyPayment(carId, id, reason));
}

export function useRecordDealerPayment(carId: string) {
  return useFinanceMutation((input: PaymentInput) => recordDealerPayment(carId, input));
}

export function useReverseDealerPayment(carId: string) {
  return useFinanceMutation(({ id, reason }: { id: string; reason: string }) => reverseDealerPayment(carId, id, reason));
}

export function useChangeCarStatus(carId: string) {
  return useFinanceMutation(({ status, reason }: { status: string; reason?: string }) => changeCarStatus(carId, status, reason));
}
