import { toast as sonnerToast } from 'sonner';

/* Re-export sonner's toast API with our conventions */
export const toast = {
  success: (message: string, options?: Parameters<typeof sonnerToast.success>[1]) =>
    sonnerToast.success(message, options),

  error: (message: string, options?: Parameters<typeof sonnerToast.error>[1]) =>
    sonnerToast.error(message, options),

  warning: (message: string, options?: Parameters<typeof sonnerToast.warning>[1]) =>
    sonnerToast.warning(message, options),

  info: (message: string, options?: Parameters<typeof sonnerToast.info>[1]) =>
    sonnerToast.info(message, options),

  promise: <T>(
    promise: Promise<T>,
    options: {
      loading: string;
      success: string | ((data: T) => string);
      error: string | ((error: unknown) => string);
    }
  ) => sonnerToast.promise(promise, options),

  custom: (message: React.ReactNode, options?: Parameters<typeof sonnerToast>[1]) =>
    sonnerToast(message, options),

  dismiss: (toastId?: string | number) => sonnerToast.dismiss(toastId),
};

/* Hook for components that need toast access */
export function useToast() {
  return toast;
}
