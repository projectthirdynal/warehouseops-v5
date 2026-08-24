import { cn } from '@/lib/utils';

const styles: Record<string, string> = {
  DRAFT: 'bg-slate-100 text-slate-600',
  PENDING_APPROVAL: 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
  APPROVED: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
  REJECTED: 'bg-rose-50 text-rose-700 ring-1 ring-rose-200',
  CANCELLED: 'bg-slate-100 text-slate-500',
  READY: 'bg-blue-50 text-blue-700 ring-1 ring-blue-200',
  ACTIVE: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
  PARTIALLY_DISTRIBUTED: 'bg-violet-50 text-violet-700 ring-1 ring-violet-200',
  FULLY_DISTRIBUTED: 'bg-blue-50 text-blue-700 ring-1 ring-blue-200',
  DISTRIBUTED: 'bg-blue-50 text-blue-700 ring-1 ring-blue-200',
  COMPLETED: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
};

export default function StatusPill({ status }: { status: string }) {
  return (
    <span
      className={cn(
        'inline-flex rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide',
        styles[status] ?? 'bg-slate-100 text-slate-600'
      )}
    >
      {status.replace(/_/g, ' ')}
    </span>
  );
}
