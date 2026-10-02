"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { createSale, fetchSale, fetchSales, recordPayment, reversePayment, reverseSale, type FoodSale, type NewSaleInput, type SaleFilters } from "./api";
import { type PaymentInput } from "@/features/restaurant-common/payments";

const salesKey = ["restaurant-sales"] as const;

export function useSales(filters: SaleFilters) {
  return useQuery({ queryKey: [...salesKey, "list", filters], queryFn: () => fetchSales(filters), placeholderData: keepPreviousData });
}

export function useSale(id: string) {
  return useQuery({ queryKey: [...salesKey, "detail", id], queryFn: () => fetchSale(id) });
}

/** Every sale mutation returns the refreshed sale; lists are refetched. */
function useSaleMutation<TArgs>(mutationFn: (args: TArgs) => Promise<FoodSale>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: (sale) => {
      queryClient.setQueryData([...salesKey, "detail", sale.id], sale);
      queryClient.invalidateQueries({ queryKey: [...salesKey, "list"] });
    },
  });
}

export function useCreateSale() {
  return useSaleMutation((input: NewSaleInput) => createSale(input));
}

export function useRecordPayment(saleId: string) {
  return useSaleMutation((input: PaymentInput) => recordPayment(saleId, input));
}

export function useReverseSale(saleId: string) {
  return useSaleMutation((reason: string) => reverseSale(saleId, reason));
}

export function useReversePayment(saleId: string) {
  return useSaleMutation(({ paymentId, reason }: { paymentId: string; reason: string }) => reversePayment(saleId, paymentId, reason));
}
