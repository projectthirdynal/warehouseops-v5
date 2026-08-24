import { PropsWithChildren, ReactNode } from 'react';

interface SectionCardProps extends PropsWithChildren {
  title: string;
  action?: ReactNode;
  className?: string;
}

export default function SectionCard({ title, action, className = '', children }: SectionCardProps) {
  return (
    <section
      className={`rounded-2xl border border-slate-200 bg-white shadow-sm shadow-slate-200/40 ${className}`}
    >
      <div className="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-4">
        <h2 className="text-base font-bold text-slate-900">{title}</h2>
        {action}
      </div>
      <div className="p-5">{children}</div>
    </section>
  );
}
