import { useEffect, useState, useCallback } from 'react';
import { useToast } from '@/hooks/use-toast';

interface LeadNotification {
  lead_id: number;
  customer_name: string;
  product: string | null;
  province: string | null;
  city: string | null;
  priority: number;
  reason?: string;
  timestamp: number;
}

/**
 * Hook for agents to receive real-time lead assignment notifications.
 *
 * Current implementation: polling fallback (checks /agent/leads endpoint).
 * Future: switch to Laravel Echo when WebSocket infrastructure is ready.
 */
export function useLeadNotifications(agentId: number | null) {
  const toast = useToast();
  const [unreadCount, setUnreadCount] = useState(0);
  const [lastNotification, setLastNotification] = useState<LeadNotification | null>(null);

  const incrementUnread = useCallback(() => {
    setUnreadCount((prev) => prev + 1);
  }, []);

  const clearUnread = useCallback(() => {
    setUnreadCount(0);
  }, []);

  // Polling fallback: check for new assignments every 30 seconds
  useEffect(() => {
    if (!agentId) return;

    const check = async () => {
      try {
        const res = await fetch('/api/agent/leads/unread-count', {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        });
        if (res.ok) {
          const data = await res.json();
          if (data.count > unreadCount) {
            setUnreadCount(data.count);
            if (data.latest) {
              setLastNotification(data.latest);
              toast.success(`New lead assigned: ${data.latest.customer_name}`);
            }
          }
        }
      } catch {
        // Silently fail — polling is best-effort
      }
    };

    check();
    const interval = setInterval(check, 30000);
    return () => clearInterval(interval);
  }, [agentId, unreadCount, toast]);

  return {
    unreadCount,
    lastNotification,
    incrementUnread,
    clearUnread,
  };
}
