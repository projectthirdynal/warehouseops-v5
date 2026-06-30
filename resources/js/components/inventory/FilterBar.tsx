import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

interface FilterBarProps {
  children: React.ReactNode;
  className?: string;
}

export function FilterBar({ children, className }: FilterBarProps) {
  return (
    <Card>
      <CardContent className={cn('flex flex-wrap items-end gap-3 p-4', className)}>
        {children}
      </CardContent>
    </Card>
  );
}

interface FilterFieldProps {
  label: string;
  children: React.ReactNode;
  className?: string;
}

export function FilterField({ label, children, className }: FilterFieldProps) {
  return (
    <div className={cn('space-y-1', className)}>
      <label className="text-xs font-medium text-muted-foreground">{label}</label>
      {children}
    </div>
  );
}
