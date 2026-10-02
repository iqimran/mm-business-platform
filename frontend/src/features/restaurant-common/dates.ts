/** Local calendar date/time helpers (not UTC, which can be a day behind in UTC+ time zones). */
const pad = (n: number) => String(n).padStart(2, "0");

export const isoDate = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

export const today = () => isoDate(new Date());

/** "YYYY-MM-DDTHH:mm" for datetime-local inputs. */
export const nowLocal = () => {
  const d = new Date();
  return `${isoDate(d)}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};
