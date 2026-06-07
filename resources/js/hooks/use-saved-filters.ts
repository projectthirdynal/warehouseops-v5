import { useCallback } from 'react';
import { router } from '@inertiajs/react';

/* Sync filter state to URL query params so they are bookmarkable and shareable.
   Use inside any Index page that has filters. */
export function useSavedFilters(route: string) {
  const apply = useCallback(
    (filters: Record<string, string | undefined | null>) => {
      const clean: Record<string, string> = {};
      Object.entries(filters).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '' && v !== 'all') {
          clean[k] = v;
        }
      });
      router.get(route, clean, { preserveState: true, replace: true });
    },
    [route]
  );

  const reset = useCallback(() => {
    router.get(route, {}, { preserveState: true, replace: true });
  }, [route]);

  return { apply, reset };
}
