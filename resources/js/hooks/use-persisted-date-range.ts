export interface DateRange {
  from: string; // YYYY-MM-DD
  to: string;   // YYYY-MM-DD
}

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function offsetDate(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

export function usePersistedDateRange(
  storageKey: string,
  urlFrom?: string,
  urlTo?: string,
): DateRange {
  if (urlFrom || urlTo) {
    return { from: urlFrom ?? '', to: urlTo ?? '' };
  }
  try {
    const stored = localStorage.getItem(storageKey);
    if (stored) return JSON.parse(stored) as DateRange;
  } catch { /* ignore */ }
  return { from: offsetDate(-29), to: today() };
}
