"use client";

import { useEffect, useState } from "react";

/** Returns `value` once it has stopped changing for `delay` ms (search boxes: one request, not one per keystroke). */
export function useDebouncedValue<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const t = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(t);
  }, [value, delay]);

  return debounced;
}
