import { API_BASE_URL } from "@/config/env";
import { apiRequest } from "@/lib/api-client";
import type { Paginated } from "@/types/api";

export const carStatuses = ["PURCHASED", "IN_STOCK", "PREPARATION", "READY_FOR_SALE", "SOLD", "COMPLETED"] as const;
export type CarStatus = (typeof carStatuses)[number];

export const carStatusLabels: Record<CarStatus, string> = {
  PURCHASED: "Purchased",
  IN_STOCK: "In stock",
  PREPARATION: "Preparation",
  READY_FOR_SALE: "Ready for sale",
  SOLD: "Sold",
  COMPLETED: "Completed",
};

export type CarImage = {
  id: string;
  original_name: string;
  mime_type: string;
  size_bytes: number;
  sort_order: number;
  file_path: string;
  created_at: string;
};

export type Car = {
  id: string;
  branch?: { id: string; code: string; name: string };
  dealer?: { id: string; name: string } | null;
  brand: string;
  model: string;
  model_year: number | null;
  color: string | null;
  chassis_number: string;
  engine_number: string | null;
  registration_number: string | null;
  registration_date: string | null;
  mileage_km: number | null;
  status: CarStatus;
  notes: string | null;
  images_count?: number;
  images?: CarImage[];
  created_at: string;
  updated_at: string;
};

export type CarInput = {
  branch_id: string;
  dealer_id: string | null;
  brand: string;
  model: string;
  model_year: number | null;
  color: string | null;
  chassis_number: string;
  engine_number: string | null;
  registration_number: string | null;
  registration_date: string | null;
  mileage_km: number | null;
  notes: string | null;
};

export type CarFilters = {
  page: number;
  search: string;
  status: "" | CarStatus;
  branchId: string;
};

export function fetchCars({ page, search, status, branchId }: CarFilters) {
  const params = new URLSearchParams({ page: String(page), per_page: "25" });
  if (search.trim()) params.set("search", search.trim());
  if (status) params.set("status", status);
  if (branchId) params.set("branch_id", branchId);

  return apiRequest<Paginated<Car>>(`/cars?${params}`);
}

export function fetchCar(id: string) {
  return apiRequest<Car>(`/cars/${encodeURIComponent(id)}`);
}

export function createCar(input: CarInput) {
  return apiRequest<Car>("/cars", { method: "POST", body: input });
}

export function updateCar(id: string, input: CarInput) {
  return apiRequest<Car>(`/cars/${encodeURIComponent(id)}`, { method: "PUT", body: input });
}

export function deleteCar(id: string) {
  return apiRequest<Record<string, never>>(`/cars/${encodeURIComponent(id)}`, { method: "DELETE" });
}

export function uploadCarImage(carId: string, file: File) {
  const form = new FormData();
  form.append("image", file);

  return apiRequest<CarImage>(`/cars/${encodeURIComponent(carId)}/images`, { method: "POST", body: form });
}

export function deleteCarImage(carId: string, imageId: string) {
  return apiRequest<Record<string, never>>(`/cars/${encodeURIComponent(carId)}/images/${encodeURIComponent(imageId)}`, {
    method: "DELETE",
  });
}

/** Image URL; served by the API only to users allowed to view the car (session cookie). */
export function carImageUrl(image: CarImage) {
  return `${API_BASE_URL}${image.file_path}`;
}
