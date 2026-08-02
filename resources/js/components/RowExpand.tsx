import { useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { cn } from '@/lib/utils';
import { ChevronDown } from 'lucide-react';

interface RowExpandProps {
  children: React.ReactNode;
  className?: string;
  defaultOpen?: boolean;
}

export function RowExpand({ children, className, defaultOpen = false }: RowExpandProps) {
  const [open, setOpen] = useState(defaultOpen);

  return (
    <div className={cn('relative', className)}>
      <button
        type="button"
        onClick={() => setOpen(!open)}
        className={cn(
          'flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted',
          open && 'text-foreground'
        )}
        aria-expanded={open}
      >
        <ChevronDown
          className={cn('h-3.5 w-3.5 transition-transform duration-200', open && 'rotate-180')}
        />
        {open ? 'Collapse' : 'Expand'}
      </button>

      <AnimatePresence initial={false}>
        {open && (
          <motion.div
            initial={{ height: 0, opacity: 0 }}
            animate={{
              height: 'auto',
              opacity: 1,
              transition: { duration: 0.22, ease: [0.25, 0.46, 0.45, 0.94] as const },
            }}
            exit={{ height: 0, opacity: 0, transition: { duration: 0.15 } }}
            className="overflow-hidden"
          >
            <div className="mt-2 rounded-md border bg-muted/30 p-3">{children}</div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}
