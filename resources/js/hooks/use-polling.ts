import { useEffect, useRef, useCallback } from 'react';

interface UsePollingOptions {
  interval?: number; /* ms, default 5000 */
  enabled?: boolean; /* default true */
  onError?: (err: unknown) => void;
  maxRetries?: number; /* default 3 */
  backoff?: boolean; /* double interval on error, default true */
}

export function usePolling(
  callback: () => Promise<void> | void,
  deps: React.DependencyList,
  options: UsePollingOptions = {}
) {
  const { interval = 5000, enabled = true, onError, maxRetries = 3, backoff = true } = options;

  const currentInterval = useRef(interval);
  const retryCount = useRef(0);
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const isMounted = useRef(true);

  const tick = useCallback(async () => {
    if (!isMounted.current || !enabled) return;
    try {
      await callback();
      retryCount.current = 0;
      currentInterval.current = interval; /* reset on success */
    } catch (err) {
      retryCount.current += 1;
      if (onError) onError(err);
      if (backoff && retryCount.current <= maxRetries) {
        currentInterval.current = Math.min(currentInterval.current * 2, 60000); /* cap at 60s */
      }
    }
    if (isMounted.current && enabled) {
      timeoutRef.current = setTimeout(tick, currentInterval.current);
    }
  }, [callback, enabled, interval, maxRetries, backoff, onError]);

  useEffect(() => {
    isMounted.current = true;
    if (enabled) {
      timeoutRef.current = setTimeout(tick, currentInterval.current);
    }
    return () => {
      isMounted.current = false;
      if (timeoutRef.current) clearTimeout(timeoutRef.current);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [enabled, ...deps]);
}
