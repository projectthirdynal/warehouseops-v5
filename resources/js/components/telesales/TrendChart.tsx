import { useMemo } from 'react';

export interface TrendPoint {
  label: string;
  assigned: number;
  contacted: number;
  orders: number;
}

interface TrendChartProps {
  data: TrendPoint[];
}

const WIDTH = 760;
const HEIGHT = 250;
const PAD_X = 28;
const PAD_Y = 26;

function buildPoints(values: number[], max: number): string {
  if (values.length === 0) return '';

  const usableWidth = WIDTH - PAD_X * 2;
  const usableHeight = HEIGHT - PAD_Y * 2;

  return values
    .map((value, index) => {
      const x =
        PAD_X +
        (values.length === 1 ? usableWidth / 2 : (index / (values.length - 1)) * usableWidth);
      const y = PAD_Y + usableHeight - (value / Math.max(max, 1)) * usableHeight;
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    })
    .join(' ');
}

export default function TrendChart({ data }: TrendChartProps) {
  const maxValue = useMemo(
    () => Math.max(1, ...data.flatMap((point) => [point.assigned, point.contacted, point.orders])),
    [data]
  );

  const assigned = buildPoints(
    data.map((point) => point.assigned),
    maxValue
  );
  const contacted = buildPoints(
    data.map((point) => point.contacted),
    maxValue
  );
  const orders = buildPoints(
    data.map((point) => point.orders),
    maxValue
  );

  if (data.length === 0) {
    return (
      <div className="flex h-[270px] items-center justify-center text-sm text-slate-400">
        No trend data available.
      </div>
    );
  }

  return (
    <div className="w-full overflow-hidden">
      <div className="mb-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-slate-500">
        <span className="inline-flex items-center gap-2">
          <span className="h-2.5 w-2.5 rounded-full bg-blue-500" /> Leads Assigned
        </span>
        <span className="inline-flex items-center gap-2">
          <span className="h-2.5 w-2.5 rounded-full bg-emerald-500" /> Leads Contacted
        </span>
        <span className="inline-flex items-center gap-2">
          <span className="h-2.5 w-2.5 rounded-full bg-amber-500" /> Orders
        </span>
      </div>

      <svg
        viewBox={`0 0 ${WIDTH} ${HEIGHT + 28}`}
        className="h-auto w-full"
        role="img"
        aria-label="Leads and orders trend"
      >
        {[0.25, 0.5, 0.75, 1].map((ratio) => {
          const y = PAD_Y + (HEIGHT - PAD_Y * 2) * (1 - ratio);
          return (
            <line
              key={ratio}
              x1={PAD_X}
              x2={WIDTH - PAD_X}
              y1={y}
              y2={y}
              stroke="rgb(226 232 240)"
              strokeWidth="1"
            />
          );
        })}

        <polyline
          points={assigned}
          fill="none"
          stroke="rgb(59 130 246)"
          strokeWidth="3"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
        <polyline
          points={contacted}
          fill="none"
          stroke="rgb(16 185 129)"
          strokeWidth="3"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
        <polyline
          points={orders}
          fill="none"
          stroke="rgb(245 158 11)"
          strokeWidth="3"
          strokeLinecap="round"
          strokeLinejoin="round"
        />

        {data.map((point, index) => {
          const labelEvery = Math.max(1, Math.ceil(data.length / 7));
          if (index % labelEvery !== 0 && index !== data.length - 1) return null;
          const x =
            PAD_X +
            (data.length === 1
              ? (WIDTH - PAD_X * 2) / 2
              : (index / (data.length - 1)) * (WIDTH - PAD_X * 2));
          return (
            <text
              key={`${point.label}-${index}`}
              x={x}
              y={HEIGHT + 16}
              textAnchor="middle"
              className="fill-slate-400 text-[11px]"
            >
              {point.label}
            </text>
          );
        })}
      </svg>
    </div>
  );
}
