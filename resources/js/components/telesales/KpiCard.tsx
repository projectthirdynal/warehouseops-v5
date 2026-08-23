import type { ComponentType } from 'react';
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import { cn } from '@/lib/utils';

interface KpiCardProps {
  label: string;
  value: string | number;
  icon: ComponentType<{ className?: string }>;
  trend?: number | null;
  trendLabel?: string;
  accent?: 'blue' | 'green' | 'purple' | 'orange' | 'teal' | 'rose';
}

const accents = {
  blue: 'bg-blue-50 text-blue-600',
  green: 'bg-emerald-50 text-emerald-600',
  purple: 'bg-violet-50 text-violet-600',
  orange: 'bg-amber-50 text-amber-600',
  teal: 'bg-cyan-50 text-cyan-600',
  rose: 'bg-rose-50 text-rose-600',
};

export default function KpiCard({
  label,
  value,
  icon: Icon,
  trend = null,
  trendLabel = 'vs previous period',
  accent = 'blue',
}: KpiCardProps) {
  const positive = trend !== null && trend > 0;
  const negative = trend !== null && trend < 0;

  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/40 transition-shadow hover:shadow-md">
      <div className="flex items-start gap-3">
        <div
          className={cn(
            'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl',
            accents[accent]
          )}
        >
          <Icon className="h-5 w-5" />
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
            {label}
          </p>
          <p className="mt-1 truncate text-2xl font-extrabold tracking-tight text-slate-950">
            {value}
          </p>
        </div>
      </div>

      <div className="mt-3 flex min-h-5 items-center gap-1 text-xs">
        {trend === null ? (
          <span className="text-slate-400">Current snapshot</span>
        ) : (
          <>
            <span
              className={cn(
                'inline-flex items-center gap-0.5 font-semibold',
                positive && 'text-emerald-600',
                negative && 'text-rose-600',
                !positive && !negative && 'text-slate-500'
              )}
            >
              {positive ? (
                <ArrowUpRight className="h-3.5 w-3.5" />
              ) : negative ? (
                <ArrowDownRight className="h-3.5 w-3.5" />
              ) : (
                <Minus className="h-3.5 w-3.5" />
              )}
              {Math.abs(trend).toFixed(1)}%
            </span>
            <span className="truncate text-slate-400">{trendLabel}</span>
          </>
        )}
      </div>
    </div>
  );
}
