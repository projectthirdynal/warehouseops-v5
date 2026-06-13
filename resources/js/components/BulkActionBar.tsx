import { motion, AnimatePresence } from 'framer-motion';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Trash2, Download, Archive,
} from 'lucide-react';

export type BulkAction = {
  id: string;
  label: string;
  icon?: React.ComponentType<{ className?: string }>;
  variant?: 'default' | 'destructive' | 'outline' | 'ghost';
  onClick: (ids: string[]) => void;
  disabled?: (ids: string[]) => boolean;
};

interface BulkActionBarProps {
  selectedIds: string[];
  totalCount: number;
  onSelectAll: (checked: boolean) => void;
  onClear: () => void;
  actions?: BulkAction[];
  className?: string;
}

const DEFAULT_BULK_ACTIONS: BulkAction[] = [
  {
    id: 'delete',
    label: 'Delete',
    icon: Trash2,
    variant: 'destructive',
    onClick: () => {},
  },
  {
    id: 'export',
    label: 'Export',
    icon: Download,
    variant: 'default',
    onClick: () => {},
  },
  {
    id: 'archive',
    label: 'Archive',
    icon: Archive,
    variant: 'outline',
    onClick: () => {},
  },
];

export function BulkActionBar({
  selectedIds,
  totalCount,
  onSelectAll,
  onClear,
  actions = DEFAULT_BULK_ACTIONS,
  className,
}: BulkActionBarProps) {
  const hasSelection = selectedIds.length > 0;
  const allSelected = totalCount > 0 && selectedIds.length === totalCount;
  const someSelected = hasSelection && !allSelected;

  return (
    <AnimatePresence>
      {hasSelection && (
        <motion.div
          initial={{ opacity: 0, y: -12, scale: 0.96 }}
          animate={{ opacity: 1, y: 0, scale: 1 }}
          exit={{ opacity: 0, y: -12, scale: 0.96 }}
          transition={{ duration: 0.18, ease: [0.25, 0.46, 0.45, 0.94] as const }}
          className={cn(
            'flex items-center gap-3 rounded-lg border bg-background/95 backdrop-blur-sm px-4 py-2.5 shadow-lg',
            className
          )}
        >
          <div className="flex items-center gap-2">
            <Checkbox
              checked={allSelected}
              ref={(el) => {
                if (el) (el as unknown as HTMLInputElement).indeterminate = someSelected;
              }}
              onCheckedChange={(checked) => onSelectAll(Boolean(checked))}
              aria-label="Select all"
            />
            <span className="text-sm font-medium">
              {selectedIds.length} selected
            </span>
          </div>

          <div className="h-6 w-px bg-border" />

          <div className="flex items-center gap-1.5">
            {actions.map((action) => {
              const Icon = action.icon;
              const disabled = action.disabled?.(selectedIds) ?? false;
              return (
                <Button
                  key={action.id}
                  variant={action.variant ?? 'default'}
                  size="sm"
                  disabled={disabled}
                  onClick={() => action.onClick(selectedIds)}
                  className="h-8 gap-1.5 text-xs"
                >
                  {Icon && <Icon className="h-3.5 w-3.5" />}
                  {action.label}
                </Button>
              );
            })}
          </div>

          <Button
            variant="ghost"
            size="sm"
            onClick={onClear}
            className="h-8 text-xs text-muted-foreground hover:text-foreground ml-auto"
          >
            Clear
          </Button>
        </motion.div>
      )}
    </AnimatePresence>
  );
}
