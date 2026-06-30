import { TrendingUp, TrendingDown, Minus } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

interface KpiCardProps {
  title: string;
  value: string | number;
  subtitle?: string;
  trend?: number;
  trendLabel?: string;
  icon?: React.ReactNode;
  className?: string;
  variant?: 'default' | 'primary' | 'success' | 'warning' | 'destructive';
  filled?: boolean;
  sparkline?: React.ReactNode;
}

const variantStyles = {
  default: 'border-border',
  primary: 'border-primary/20 bg-primary/5',
  success: 'border-success/20 bg-success/5',
  warning: 'border-warning/20 bg-warning/5',
  destructive: 'border-destructive/20 bg-destructive/5',
};

const filledStyles = {
  default: 'bg-ink text-ink-foreground border-ink',
  primary: 'bg-primary text-primary-foreground border-primary',
  success: 'bg-success text-success-foreground border-success',
  warning: 'bg-warning text-warning-foreground border-warning',
  destructive: 'bg-destructive text-destructive-foreground border-destructive',
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
  filled = false,
  sparkline,
}: KpiCardProps) {
  const isUp = trend !== undefined && trend > 0;
  const isDown = trend !== undefined && trend < 0;
  const TrendIcon = isUp ? TrendingUp : isDown ? TrendingDown : Minus;
  const trendColor = isUp ? 'text-success' : isDown ? 'text-destructive' : 'text-muted-foreground';

  const filledCls = filled ? filledStyles[variant] : variantStyles[variant];
  const filledText = filled ? 'text-ink-foreground/80' : 'text-muted-foreground';
  const filledValue = filled ? 'text-ink-foreground' : 'text-foreground';
  const filledSub = filled ? 'text-ink-foreground/60' : 'text-muted-foreground';
  const filledTrend = filled ? 'text-ink-foreground/80' : trendColor;
  const filledTrendLabel = filled ? 'text-ink-foreground/60' : 'text-muted-foreground';

  return (
    <Card className={cn(filledCls, className)}>
      <CardContent className="p-5">
        <div className="flex items-start justify-between">
          <div className="space-y-1">
            <p className={cn('text-sm', filledText)}>{title}</p>
            <p
              className={cn(
                'text-3xl font-bold tracking-tight font-display tabular-nums',
                filledValue
              )}
            >
              {value}
            </p>
            {subtitle && <p className={cn('text-xs', filledSub)}>{subtitle}</p>}
          </div>
          {icon && (
            <div
              className={cn(
                'rounded-lg p-2',
                filled ? 'bg-ink-foreground/10' : 'bg-background shadow-sm'
              )}
            >
              {icon}
            </div>
          )}
        </div>
        {trend !== undefined && (
          <div className="mt-3 flex items-center gap-1.5">
            <TrendIcon className={cn('h-3.5 w-3.5', filledTrend)} />
            <span className={cn('text-xs font-medium tabular-nums', filledTrend)}>
              {isUp ? '+' : ''}
              {trend}%
            </span>
            {trendLabel && <span className={cn('text-xs', filledTrendLabel)}>{trendLabel}</span>}
          </div>
        )}
        {sparkline && <div className="mt-3">{sparkline}</div>}
      </CardContent>
    </Card>
  );
}
