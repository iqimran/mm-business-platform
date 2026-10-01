"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import type { PaymentInput } from "@/features/restaurant-sales/api";
import {
  cancelBooking,
  completeBooking,
  createBooking,
  fetchAvailability,
  fetchBooking,
  fetchBookings,
  fetchHalls,
  recordBookingPayment,
  reverseBookingPayment,
  updateBooking,
  type BookingFilters,
  type BookingInput,
} from "./api";

const bookingsKey = ["restaurant-bookings"] as const;

export function useBookings(filters: BookingFilters) {
  return useQuery({ queryKey: [...bookingsKey, "list", filters], queryFn: () => fetchBookings(filters), placeholderData: keepPreviousData });
}

export function useBooking(id: string) {
  return useQuery({ queryKey: [...bookingsKey, "detail", id], queryFn: () => fetchBooking(id) });
}

export function useHallOptions(activeOnly: boolean) {
  return useQuery({ queryKey: ["restaurant/halls", "options", activeOnly], queryFn: () => fetchHalls(activeOnly), staleTime: 60_000 });
}

export function useAvailability(hallId: string, date: string) {
  return useQuery({
    queryKey: [...bookingsKey, "availability", hallId, date],
    queryFn: () => fetchAvailability(hallId, date),
    enabled: Boolean(hallId && /^\d{4}-\d{2}-\d{2}$/.test(date)),
  });
}

/** Every booking mutation returns the refreshed booking; lists and availability are refetched. */
function useBookingMutation<TArgs>(mutationFn: (args: TArgs) => Promise<{ id: string }>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: (booking) => {
      queryClient.setQueryData([...bookingsKey, "detail", booking.id], booking);
      queryClient.invalidateQueries({ queryKey: [...bookingsKey, "list"] });
      queryClient.invalidateQueries({ queryKey: [...bookingsKey, "availability"] });
    },
  });
}

export function useCreateBooking() {
  return useBookingMutation((input: BookingInput) => createBooking(input));
}

export function useUpdateBooking(id: string) {
  return useBookingMutation((input: Omit<BookingInput, "payment">) => updateBooking(id, input));
}

export function useCancelBooking(id: string) {
  return useBookingMutation((reason: string) => cancelBooking(id, reason));
}

export function useCompleteBooking(id: string) {
  return useBookingMutation(() => completeBooking(id));
}

export function useRecordBookingPayment(id: string) {
  return useBookingMutation((input: PaymentInput) => recordBookingPayment(id, input));
}

export function useReverseBookingPayment(id: string) {
  return useBookingMutation(({ paymentId, reason }: { paymentId: string; reason: string }) => reverseBookingPayment(id, paymentId, reason));
}
