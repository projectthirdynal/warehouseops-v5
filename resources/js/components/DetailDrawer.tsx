import { useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { cn } from '@/lib/utils';
import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';

interface DetailDrawerProps {
  open: boolean;
  onClose: () => void;
  title: string;
  children: React.ReactNode;
  className?: string;
  width?: string;
}

export function DetailDrawer({
  open,
  onClose,
  title,
  children,
  className,
  width = '480px',
}: DetailDrawerProps) {
  /* Lock body scroll while open */
  useEffect(() => {
    if (open) {
      const prev = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      return () => { document.body.style.overflow = prev; };
    }
  }, [open]);

  /* Close on Escape */
  useEffect(() => {
    const handle = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    if (open) { window.addEventListener('keydown', handle); return () => window.removeEventListener('keydown', handle); }
  }, [open, onClose]);

  return (
    <AnimatePresence>
      {open && (
        <>
          {/* Backdrop */}
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.15 }}
            className="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm"
            onClick={onClose}
          />
          {/* Panel */}
          <motion.div
            initial={{ x: '100%' }}
            animate={{ x: 0 }}
            exit={{ x: '100%' }}
            transition={{ duration: 0.25, ease: [0.25, 0.46, 0.45, 0.94] as const }}
            style={{ width, maxWidth: '90vw' }}
            className={cn(
              'fixed right-0 top-0 bottom-0 z-50 flex flex-col bg-background border-l shadow-2xl',
              className
            )}
          >
            <div className="flex items-center justify-between border-b px-5 py-4">
              <h2 className="text-base font-semibold">{title}</h2>
              <Button variant="ghost" size="icon" onClick={onClose} className="h-8 w-8">
                <X className="h-4 w-4" />
              </Button>
            </div>
            <div className="flex-1 overflow-y-auto p-5">
              {children}
            </div>
          </motion.div>
        </>
      )}
    </AnimatePresence>
  );
}
