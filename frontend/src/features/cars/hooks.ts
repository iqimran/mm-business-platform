"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { createCar, deleteCar, deleteCarImage, fetchCar, fetchCars, updateCar, uploadCarImage, type CarFilters, type CarInput } from "./api";

const carsKey = ["cars"] as const;

export function useCars(filters: CarFilters) {
  return useQuery({ queryKey: [...carsKey, "list", filters], queryFn: () => fetchCars(filters), placeholderData: keepPreviousData });
}

export function useCar(id: string) {
  return useQuery({ queryKey: [...carsKey, "detail", id], queryFn: () => fetchCar(id) });
}

function useCarMutation<TArgs, TResult>(mutationFn: (args: TArgs) => Promise<TResult>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: carsKey }),
  });
}

export function useCreateCar() {
  return useCarMutation((input: CarInput) => createCar(input));
}

export function useUpdateCar(id: string) {
  return useCarMutation((input: CarInput) => updateCar(id, input));
}

export function useDeleteCar() {
  return useCarMutation((id: string) => deleteCar(id));
}

export function useUploadCarImage(carId: string) {
  return useCarMutation((file: File) => uploadCarImage(carId, file));
}

export function useDeleteCarImage(carId: string) {
  return useCarMutation((imageId: string) => deleteCarImage(carId, imageId));
}
