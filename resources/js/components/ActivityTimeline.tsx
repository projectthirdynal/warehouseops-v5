import { formatDate } from '@/lib/utils';
import { ArrowDownCircle, ArrowUpCircle, RefreshCw, HelpCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface TimelineEvent {
  id: number | string;
  type: string;
  quantity: number;
  notes?: string | null;
  created_at: string;
  warehouse_name?: string | null;
  performer_name?: string | null;
  supply_name?: string | null;
  supply_sku?: string | null;
}

interface Props {
  events: TimelineEvent[];
  emptyMessage?: string;
  className?: string;
  /** show supply name/sku on each item (useful on dashboard) */
  showSupply?: boolean;
}

const TYPE_META: Record<
  string,
  { label: string; icon: React.ReactNode; dotCls: string; qtySign: 'pos' | 'neg' | 'neutral' }
> = {
  STOCK_IN: {
    label: 'Stock In',
    icon: <ArrowUpCircle className="h-3.5 w-3.5" />,
    dotCls: 'bg-success',
    qtySign: 'pos',
  },
  STOCK_OUT: {
    label: 'Stock Out',
    icon: <ArrowDownCircle className="h-3.5 w-3.5" />,
    dotCls: 'bg-destructive/50',
    qtySign: 'neg',
  },
  ADJUSTMENT: {
    label: 'Adjustment',
    icon: <RefreshCw className="h-3.5 w-3.5" />,
    dotCls: 'bg-warning/50',
    qtySign: 'neutral',
  },
};

const QTY_CLASSES: Record<'pos' | 'neg' | 'neutral', string> = {
  pos: 'text-success/80',
  neg: 'text-destructive/80',
  neutral: 'text-warning/80',
};

export function ActivityTimeline({
  events,
  emptyMessage = 'No activity recorded.',
  className,
  showSupply = false,
}: Props) {
  if (events.length === 0) {
    return (
      <div
        className={cn(
          'flex items-center justify-center py-8 text-sm text-muted-foreground',
          className
        )}
      >
        {emptyMessage}
      </div>
    );
  }

  return (
    <ol className={cn('relative border-l border-border ml-3', className)}>
      {events.map((ev, idx) => {
        const meta = TYPE_META[ev.type] ?? {
          label: ev.type,
          icon: <HelpCircle className="h-3.5 w-3.5" />,
          dotCls: 'bg-muted-foreground',
          qtySign: 'neutral' as const,
        };
        const qtyPrefix = ev.quantity > 0 ? '+' : '';
        const qtyCls = QTY_CLASSES[meta.qtySign];

        return (
          <li key={ev.id} className={cn('ml-4', idx < events.length - 1 && 'mb-4')}>
            {/* Dot */}
            <span
              className={cn(
                'absolute -left-[7px] flex h-3.5 w-3.5 items-center justify-center rounded-full ring-2 ring-background',
                meta.dotCls
              )}
            />

            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5">
              <span className="flex items-center gap-1 text-xs font-semibold text-foreground">
                {meta.icon}
                {meta.label}
              </span>
              <span className={cn('text-xs font-bold tabular-nums', qtyCls)}>
                {qtyPrefix}
                {ev.quantity.toLocaleString()}
              </span>
            </div>

            {/* Sub-line */}
            <p className="mt-0.5 text-[11px] text-muted-foreground">
              {ev.warehouse_name && <span>{ev.warehouse_name}</span>}
              {ev.warehouse_name && ev.performer_name && <span> · </span>}
              {ev.performer_name && <span>{ev.performer_name}</span>}
              {(ev.warehouse_name || ev.performer_name) && <span className="mx-1">·</span>}
              <span>{formatDate(ev.created_at)}</span>
            </p>

            {showSupply && (ev.supply_name || ev.supply_sku) && (
              <p className="mt-0.5 text-[11px] text-muted-foreground">
                {ev.supply_sku && <span className="font-mono">{ev.supply_sku}</span>}
                {ev.supply_sku && ev.supply_name && ' — '}
                {ev.supply_name}
              </p>
            )}

            {ev.notes && (
              <p className="mt-0.5 text-[11px] italic text-muted-foreground">{ev.notes}</p>
            )}
          </li>
        );
      })}
    </ol>
  );
}
