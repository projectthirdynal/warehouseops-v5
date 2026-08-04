import { useEffect, useState } from 'react';
import { Download, WifiOff, X, Smartphone } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { usePWA } from '@/hooks/use-pwa';

export function PWAInstallBanner() {
  const { isInstallable, isInstalled, isOnline, install } = usePWA();
  const [dismissed, setDismissed] = useState(false);
  const [showOffline, setShowOffline] = useState(false);

  useEffect(() => {
    if (!isOnline) {
      setShowOffline(true);
    } else {
      const timer = setTimeout(() => setShowOffline(false), 3000);
      return () => clearTimeout(timer);
    }
  }, [isOnline]);

  if (isInstalled || dismissed) return null;

  return (
    <>
      {/* Offline indicator */}
      {showOffline && (
        <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded-full bg-destructive px-4 py-2 text-sm font-medium text-destructive-foreground shadow-lg">
          <WifiOff className="mr-1.5 inline h-4 w-4" />
          You're offline — cached data is being used
        </div>
      )}

      {/* Online confirmation */}
      {!showOffline && !isOnline && null}

      {/* Install prompt */}
      {isInstallable && !dismissed && (
        <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2 max-w-md rounded-xl border bg-card p-4 shadow-xl">
          <div className="flex items-start gap-3">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10">
              <Smartphone className="h-5 w-5 text-primary" />
            </div>
            <div className="flex-1">
              <p className="text-sm font-semibold">Install TECC Agent Portal</p>
              <p className="mt-0.5 text-xs text-muted-foreground">
                Add to your home screen for quick access and offline lead viewing
              </p>
              <div className="mt-2 flex gap-2">
                <Button size="sm" onClick={install}>
                  <Download className="mr-1 h-3.5 w-3.5" />
                  Install
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setDismissed(true)}>
                  Not now
                </Button>
              </div>
            </div>
            <button
              onClick={() => setDismissed(true)}
              className="text-muted-foreground hover:text-foreground"
            >
              <X className="h-4 w-4" />
            </button>
          </div>
        </div>
      )}
    </>
  );
}
