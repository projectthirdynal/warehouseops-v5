export interface BreakdownItem {
  label: string;
  value: number;
}

interface DonutBreakdownProps {
  items: BreakdownItem[];
  total?: number;
  emptyLabel?: string;
}

const palette = [
  'rgb(59 130 246)',
  'rgb(6 182 212)',
  'rgb(52 211 153)',
  'rgb(245 158 11)',
  'rgb(148 163 184)',
  'rgb(139 92 246)',
];

function conic(items: BreakdownItem[], total: number): string {
  if (total <= 0) return 'conic-gradient(rgb(226 232 240) 0deg 360deg)';

  let cursor = 0;
  const segments = items.map((item, index) => {
    const start = cursor;
    const span = (item.value / total) * 360;
    cursor += span;
    return `${palette[index % palette.length]} ${start}deg ${cursor}deg`;
  });

  if (cursor < 360) segments.push(`rgb(226 232 240) ${cursor}deg 360deg`);
  return `conic-gradient(${segments.join(', ')})`;
}

export default function DonutBreakdown({
  items,
  total: providedTotal,
  emptyLabel = 'No data',
}: DonutBreakdownProps) {
  const total = providedTotal ?? items.reduce((sum, item) => sum + item.value, 0);

  return (
    <div className="grid gap-5 sm:grid-cols-[170px_1fr] sm:items-center">
      <div
        className="relative mx-auto h-40 w-40 shrink-0 rounded-full"
        style={{ background: conic(items, total) }}
      >
        <div className="absolute inset-7 flex flex-col items-center justify-center rounded-full bg-white shadow-inner">
          <span className="text-[11px] text-slate-400">Total</span>
          <span className="text-xl font-extrabold text-slate-950">{total.toLocaleString()}</span>
        </div>
      </div>

      <div className="space-y-3">
        {items.length === 0 ? (
          <p className="text-sm text-slate-400">{emptyLabel}</p>
        ) : (
          items.map((item, index) => {
            const pct = total > 0 ? (item.value / total) * 100 : 0;
            return (
              <div key={`${item.label}-${index}`} className="flex items-start gap-2.5">
                <span
                  className="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-sm"
                  style={{ backgroundColor: palette[index % palette.length] }}
                />
                <div className="min-w-0 flex-1">
                  <div className="flex items-center justify-between gap-2">
                    <p className="truncate text-xs font-semibold text-slate-700">{item.label}</p>
                    <p className="shrink-0 text-xs font-medium text-slate-500">{pct.toFixed(1)}%</p>
                  </div>
                  <p className="mt-0.5 text-[11px] text-slate-400">{item.value.toLocaleString()}</p>
                </div>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
}
