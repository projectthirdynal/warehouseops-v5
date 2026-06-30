import { Bell } from 'lucide-react';
import { cn } from '@/lib/utils';

interface Props {
  count: number;
  onClick?: () => void;
}

export function LeadNotificationBadge({ count, onClick }: Props) {
  return (
    <button
      onClick={onClick}
      className="relative inline-flex items-center justify-center rounded-full p-2 hover:bg-accent transition-colors"
      aria-label={`${count} unread lead notifications`}
    >
      <Bell className="h-5 w-5 text-muted-foreground" />
      {count > 0 && (
        <span
          className={cn(
            'absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-medium text-white',
            count > 9 ? 'bg-destructive/50' : 'bg-primary'
          )}
        >
          {count > 99 ? '99+' : count}
        </span>
      )}
    </button>
  );
}
