import { useEffect, useRef, useCallback } from 'react';

export function useDebounce<T extends (...args: never[]) => void>(
  fn: T,
  delay: number
): (...args: Parameters<T>) => void {
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const fnRef = useRef<T>(fn);

  useEffect(() => { fnRef.current = fn; });
  useEffect(() => () => { if (timer.current) clearTimeout(timer.current); }, []);

  return useCallback((...args: Parameters<T>) => {
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => fnRef.current(...args), delay);
  }, [delay]);
}
