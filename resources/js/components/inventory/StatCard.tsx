import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

interface StatCardProps {
  label: string;
  value: string | number;
  tone?: 'default' | 'success' | 'warning' | 'danger' | 'info';
  className?: string;
}

const toneStyles: Record<string, string> = {
  default: '',
  success: 'text-success',
  warning: 'text-warning',
  danger: 'text-destructive',
  info: 'text-info',
};

export function StatCard({ label, value, tone = 'default', className }: StatCardProps) {
  return (
    <Card className={className}>
      <CardContent className="p-4">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className={cn('mt-1 text-xl font-bold tabular-nums font-display', toneStyles[tone])}>
          {value}
        </p>
      </CardContent>
    </Card>
  );
}
