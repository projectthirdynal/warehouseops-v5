import { cn } from '@/lib/utils';
import { CheckCircle, AlertCircle, Info, Clock, type LucideIcon } from 'lucide-react';

export interface ActivityItem {
  id: string;
  title: string;
  description?: string;
  timestamp: string;
  type?: 'success' | 'warning' | 'error' | 'info';
  icon?: LucideIcon;
}

const typeIconMap: Record<string, LucideIcon> = {
  success: CheckCircle,
  warning: AlertCircle,
  error: AlertCircle,
  info: Info,
};

const typeColorMap: Record<string, string> = {
  success: 'bg-emerald-500',
  warning: 'bg-amber-500',
  error: 'bg-red-500',
  info: 'bg-blue-500',
};

interface ActivityFeedProps {
  items: ActivityItem[];
  className?: string;
  emptyMessage?: string;
}

export function ActivityFeed({ items, className, emptyMessage = 'No recent activity' }: ActivityFeedProps) {
  if (items.length === 0) {
    return (
      <div className={cn('flex flex-col items-center justify-center py-8 text-muted-foreground', className)}>
        <Clock className="h-6 w-6 mb-2 opacity-40" />
        <p className="text-sm">{emptyMessage}</p>
      </div>
    );
  }

  return (
    <div className={cn('relative space-y-0', className)}>
      {/* Vertical line */}
      <div className="absolute left-[15px] top-2 bottom-2 w-px bg-border" />
      {items.map((item) => {
        const Icon = item.icon || typeIconMap[item.type || 'info'] || Info;
        const dotColor = typeColorMap[item.type || 'info'] || 'bg-muted-foreground';
        return (
          <div key={item.id} className="relative flex gap-4 py-3 group">
            {/* Icon / Dot */}
            <div className={cn('relative z-10 flex h-7 w-7 shrink-0 items-center justify-center rounded-full ring-2 ring-background', dotColor)}>
              <Icon className="h-3.5 w-3.5 text-white" />
            </div>
            {/* Content */}
            <div className="flex-1 space-y-0.5 pt-0.5">
              <p className="text-sm font-medium leading-none">{item.title}</p>
              {item.description && (
                <p className="text-xs text-muted-foreground">{item.description}</p>
              )}
              <p className="text-[11px] text-muted-foreground/60">{item.timestamp}</p>
            </div>
          </div>
        );
      })}
    </div>
  );
}
