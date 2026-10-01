"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  addCarDocument,
  deleteCarDocument,
  fetchCarDocuments,
  fetchExpiringDocuments,
  fetchExpirySummary,
  updateCarDocument,
  type DocumentInput,
  type ExpiryFilters,
} from "./api";

const docsKey = ["car-documents"] as const;

export function useCarDocuments(carId: string, enabled: boolean) {
  return useQuery({ queryKey: [...docsKey, "car", carId], queryFn: () => fetchCarDocuments(carId), enabled });
}

export function useExpiringDocuments(filters: ExpiryFilters) {
  return useQuery({ queryKey: [...docsKey, "expiring", filters], queryFn: () => fetchExpiringDocuments(filters), placeholderData: keepPreviousData });
}

export function useExpirySummary(enabled: boolean) {
  return useQuery({ queryKey: [...docsKey, "summary"], queryFn: fetchExpirySummary, enabled });
}

function useDocumentMutation<TArgs>(mutationFn: (args: TArgs) => Promise<unknown>) {
  const queryClient = useQueryClient();

  // Any change can affect the car's list, the expiry page and the dashboard counts.
  return useMutation({ mutationFn, onSuccess: () => queryClient.invalidateQueries({ queryKey: docsKey }) });
}

export function useSaveCarDocument(carId: string) {
  return useDocumentMutation(({ id, input }: { id?: string; input: DocumentInput }) =>
    id ? updateCarDocument(carId, id, input) : addCarDocument(carId, input),
  );
}

export function useDeleteCarDocument(carId: string) {
  return useDocumentMutation((id: string) => deleteCarDocument(carId, id));
}
