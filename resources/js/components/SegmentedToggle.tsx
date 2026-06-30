import { cn } from '@/lib/utils';

interface SegmentedToggleProps {
  options: { label: string; value: string }[];
  value: string;
  onChange: (value: string) => void;
  className?: string;
}

export function SegmentedToggle({ options, value, onChange, className }: SegmentedToggleProps) {
  return (
    <div
      className={cn(
        'inline-flex items-center rounded-full bg-muted p-1 text-muted-foreground',
        className
      )}
    >
      {options.map((opt) => (
        <button
          key={opt.value}
          type="button"
          onClick={() => onChange(opt.value)}
          className={cn(
            'inline-flex items-center justify-center whitespace-nowrap rounded-full px-3 py-1.5 text-sm font-medium transition-all',
            value === opt.value
              ? 'bg-background text-foreground shadow-sm'
              : 'hover:text-foreground'
          )}
        >
          {opt.label}
        </button>
      ))}
    </div>
  );
}
