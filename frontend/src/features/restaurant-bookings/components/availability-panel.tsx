"use client";

import { errorMessage } from "@/lib/form-errors";
import { useAvailability } from "../hooks";
import { overlapping } from "../schemas";

/**
 * Booked slots of the selected hall on the selected date, with an overlap warning.
 * A preview only: the server re-checks availability (and the database blocks races).
 */
export function AvailabilityPanel({ hallId, date, start, end, ignoreBookingId }: { hallId: string; date: string; start: string; end: string; ignoreBookingId?: string }) {
  const availability = useAvailability(hallId, date);

  if (!hallId || !date) return <p className="text-sm text-muted-foreground">Select a hall and date to see existing bookings.</p>;
  if (availability.isPending) return <p className="text-sm text-muted-foreground">Checking availability…</p>;
  if (availability.isError) return <p className="text-sm text-destructive">{errorMessage(availability.error)}</p>;

  const booked = availability.data.booked.filter((slot) => slot.id !== ignoreBookingId);
  const clashes = overlapping(booked, start, end);

  return (
    <div className="flex flex-col gap-2 rounded-lg border bg-muted/30 p-3 text-sm" aria-live="polite">
      {booked.length === 0 ? (
        <p className="text-muted-foreground">No other bookings for this hall on {date}.</p>
      ) : (
        <>
          <p className="font-medium">Already booked on {date}:</p>
          <ul className="flex flex-wrap gap-2">
            {booked.map((slot) => (
              <li
                key={slot.id}
                className={`rounded-md border px-2 py-0.5 tabular-nums ${clashes.includes(slot) ? "border-destructive text-destructive" : ""}`}
              >
                {slot.start_time}–{slot.end_time} ({slot.booking_no})
              </li>
            ))}
          </ul>
        </>
      )}
      {clashes.length > 0 ? <p className="text-destructive">The selected time overlaps an existing booking.</p> : null}
    </div>
  );
}
