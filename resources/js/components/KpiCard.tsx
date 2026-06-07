import { TrendingUp, TrendingDown, Minus } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

interface KpiCardProps {
  title: string;
  value: string | number;
  subtitle?: string;
  trend?: number; /* positive = up, negative = down, 0 = neutral */
  trendLabel?: string;
  icon?: React.ReactNode;
  className?: string;
  variant?: 'default' | 'primary' | 'success' | 'warning' | 'destructive';
}

const variantStyles = {
  default: 'border-border',
  primary: 'border-primary/20 bg-primary/5',
  success: 'border-emerald-500/20 bg-emerald-50/50 dark:bg-emerald-950/20',
  warning: 'border-amber-500/20 bg-amber-50/50 dark:bg-amber-950/20',
  destructive: 'border-red-500/20 bg-red-50/50 dark:bg-red-950/20',
};

export function KpiCard({
  title,
  value,
  subtitle,
  trend,
  trendLabel,
  icon,
  className,
  variant = 'default',
}: KpiCardProps) {
  const isUp = trend !== undefined && trend > 0;
  const isDown = trend !== undefined && trend < 0;
  const TrendIcon = isUp ? TrendingUp : isDown ? TrendingDown : Minus;
  const trendColor = isUp ? 'text-emerald-600' : isDown ? 'text-red-600' : 'text-muted-foreground';

  return (
    <Card className={cn(variantStyles[variant], className)}>
      <CardContent className="p-5">
        <div className="flex items-start justify-between">
          <div className="space-y-1">
            <p className="text-sm text-muted-foreground">{title}</p>
            <p className="text-2xl font-bold tracking-tight">{value}</p>
            {subtitle && <p className="text-xs text-muted-foreground">{subtitle}</p>}
          </div>
          {icon && <div className="rounded-lg bg-background p-2 shadow-sm">{icon}</div>}
        </div>
        {trend !== undefined && (
          <div className="mt-3 flex items-center gap-1.5">
            <TrendIcon className={cn('h-3.5 w-3.5', trendColor)} />
            <span className={cn('text-xs font-medium', trendColor)}>
              {isUp ? '+' : ''}{trend}%
            </span>
            {trendLabel && <span className="text-xs text-muted-foreground">{trendLabel}</span>}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
