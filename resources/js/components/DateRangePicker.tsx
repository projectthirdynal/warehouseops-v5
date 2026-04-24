import { useState, useEffect } from 'react';
import { CalendarDays, ChevronDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

// ─── Types ────────────────────────────────────────────────────────────────────

export interface DateRange {
  from: string; // YYYY-MM-DD
  to: string;   // YYYY-MM-DD
}

interface Props {
  value: DateRange;
  onChange: (range: DateRange) => void;
  storageKey?: string;
  className?: string;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function offsetDate(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

function firstOfMonth(offset = 0): string {
  const d = new Date();
  d.setMonth(d.getMonth() + offset, 1);
  return d.toISOString().slice(0, 10);
}

function lastOfMonth(offset = 0): string {
  const d = new Date();
  d.setMonth(d.getMonth() + offset + 1, 0);
  return d.toISOString().slice(0, 10);
}

function daysBetween(from: string, to: string): number {
  if (!from || !to) return 0;
  const diff = new Date(to).getTime() - new Date(from).getTime();
  return Math.round(diff / 86_400_000) + 1;
}

function formatShort(dateStr: string): string {
  if (!dateStr) return '';
  return new Date(dateStr + 'T00:00:00').toLocaleDateString('en-PH', {
    month: 'short', day: 'numeric',
  });
}

// ─── Presets ──────────────────────────────────────────────────────────────────

const PRESETS: Array<{ label: string; getRange: () => DateRange }> = [
  { label: 'Today',       getRange: () => ({ from: today(), to: today() }) },
  { label: 'Yesterday',   getRange: () => ({ from: offsetDate(-1), to: offsetDate(-1) }) },
  { label: 'Last 7 days', getRange: () => ({ from: offsetDate(-6), to: today() }) },
  { label: 'Last 30 days',getRange: () => ({ from: offsetDate(-29), to: today() }) },
  { label: 'This month',  getRange: () => ({ from: firstOfMonth(), to: lastOfMonth() }) },
  { label: 'Last month',  getRange: () => ({ from: firstOfMonth(-1), to: lastOfMonth(-1) }) },
];

// ─── Component ────────────────────────────────────────────────────────────────

export function DateRangePicker({ value, onChange, storageKey, className }: Props) {
  const [customFrom, setCustomFrom] = useState(value.from ?? '');
  const [customTo, setCustomTo] = useState(value.to ?? '');

  // Sync local state when value prop changes (e.g. URL navigation)
  useEffect(() => {
    setCustomFrom(value.from ?? '');
    setCustomTo(value.to ?? '');
  }, [value.from, value.to]);

  const emit = (range: DateRange) => {
    if (storageKey) {
      try { localStorage.setItem(storageKey, JSON.stringify(range)); } catch { /* ignore */ }
    }
    onChange(range);
  };

  const applyPreset = (preset: { label: string; getRange: () => DateRange }) => {
    const range = preset.getRange();
    setCustomFrom(range.from);
    setCustomTo(range.to);
    emit(range);
  };

  const applyCustom = () => {
    if (!customFrom || !customTo) return;
    const from = customFrom <= customTo ? customFrom : customTo;
    const to   = customFrom <= customTo ? customTo   : customFrom;
    emit({ from, to });
  };

  const days = value.from && value.to ? daysBetween(value.from, value.to) : null;

  const label = value.from && value.to
    ? value.from === value.to
      ? formatShort(value.from)
      : `${formatShort(value.from)} – ${formatShort(value.to)}`
    : 'Date range';

  return (
    <div className={cn('flex items-center gap-2', className)}>
      {/* Presets dropdown */}
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="outline" className="gap-1.5">
            <CalendarDays className="h-4 w-4" />
            <span className="hidden sm:inline">{label}</span>
            {days !== null && (
              <span className="text-xs text-muted-foreground">({days}d)</span>
            )}
            <ChevronDown className="h-3.5 w-3.5 text-muted-foreground" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" className="w-56">
          {PRESETS.map((preset) => (
            <DropdownMenuItem key={preset.label} onClick={() => applyPreset(preset)}>
              {preset.label}
            </DropdownMenuItem>
          ))}
          <DropdownMenuSeparator />
          {/* Custom range inputs */}
          <div className="px-2 py-2 space-y-2">
            <p className="text-xs font-medium text-muted-foreground">Custom range</p>
            <div className="flex items-center gap-1.5">
              <input
                type="date"
                value={customFrom}
                max={customTo || undefined}
                onChange={(e) => setCustomFrom(e.target.value)}
                className="flex-1 h-7 rounded border bg-background px-1.5 text-xs"
              />
              <span className="text-xs text-muted-foreground">–</span>
              <input
                type="date"
                value={customTo}
                min={customFrom || undefined}
                onChange={(e) => setCustomTo(e.target.value)}
                className="flex-1 h-7 rounded border bg-background px-1.5 text-xs"
              />
            </div>
            <Button
              size="sm"
              className="w-full h-7 text-xs"
              disabled={!customFrom || !customTo}
              onClick={applyCustom}
            >
              Apply
            </Button>
          </div>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}

// ─── Hook: restore persisted range from localStorage ─────────────────────────

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
