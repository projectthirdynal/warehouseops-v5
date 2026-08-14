import { Head } from '@inertiajs/react';
import { useState, useEffect, useCallback } from 'react';
import {
  Smartphone,
  Bell,
  Wifi,
  WifiOff,
  Download,
  Trash2,
  CheckCircle,
  XCircle,
  RefreshCw,
  Database,
} from 'lucide-react';
import AgentLayout from '@/layouts/AgentLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { usePWA } from '@/hooks/use-pwa';
import { getCachedLeads, clearCachedLeads } from '@/lib/offline-leads';

export default function PWASettings() {
  const { isInstallable, isInstalled, isOnline, swRegistered, install, clearApiCache } = usePWA();
  const [pushSubscribed, setPushSubscribed] = useState(false);
  const [pushLoading, setPushLoading] = useState(false);
  const [cachedLeadsCount, setCachedLeadsCount] = useState(0);
  const [lastCached, setLastCached] = useState<string | null>(null);
  const [clearing, setClearing] = useState(false);

  const loadCacheInfo = useCallback(async () => {
    const { leads, lastCached: cachedAt } = await getCachedLeads();
    setCachedLeadsCount(leads.length);
    setLastCached(cachedAt);
  }, []);

  useEffect(() => {
    loadCacheInfo();
    // Check push subscription status
    fetch('/api/push/status')
      .then((r) => r.json())
      .then((data) => setPushSubscribed(data.subscribed ?? false))
      .catch(() => {});
  }, [loadCacheInfo]);

  const handleSubscribePush = async () => {
    setPushLoading(true);
    try {
      const reg = await navigator.serviceWorker?.getRegistration();
      if (!reg) {
        setPushLoading(false);
        return;
      }

      // Request notification permission
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') {
        setPushLoading(false);
        return;
      }

      // Subscribe to push
      const subscription = await reg.pushManager.subscribe({
        userVisibleOnly: true,
      });

      // Send subscription to server
      const sub = subscription.toJSON();
      await fetch('/api/push/subscribe', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({
          endpoint: sub.endpoint,
          keys: sub.keys,
          contentEncoding: (sub as Record<string, unknown>).contentEncoding ?? 'aesgcm',
        }),
      });

      setPushSubscribed(true);
    } catch {
      // Push subscription failed
    } finally {
      setPushLoading(false);
    }
  };

  const handleUnsubscribePush = async () => {
    setPushLoading(true);
    try {
      const reg = await navigator.serviceWorker?.getRegistration();
      if (reg) {
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
          await sub.unsubscribe();
          await fetch('/api/push/unsubscribe', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN':
                document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ endpoint: sub.endpoint }),
          });
        }
      }
      setPushSubscribed(false);
    } catch {
      // Unsubscribe failed
    } finally {
      setPushLoading(false);
    }
  };

  const handleClearCache = async () => {
    setClearing(true);
    await clearCachedLeads();
    await clearApiCache();
    setCachedLeadsCount(0);
    setLastCached(null);
    setClearing(false);
  };

  return (
    <AgentLayout>
      <Head title="PWA Settings" />
      <div className="space-y-6">
        <div>
          <h1 className="text-xl font-bold font-display tracking-tight">App Settings</h1>
          <p className="text-muted-foreground">
            Manage offline access, notifications, and app installation
          </p>
        </div>

        {/* Installation Status */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-sm font-medium">
              <Smartphone className="h-4 w-4" /> App Installation
            </CardTitle>
            <CardDescription>Install the app on your device for quick access</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between rounded-lg border p-3">
              <div className="flex items-center gap-3">
                {isInstalled ? (
                  <CheckCircle className="h-5 w-5 text-success" />
                ) : (
                  <XCircle className="h-5 w-5 text-muted-foreground" />
                )}
                <div>
                  <p className="text-sm font-medium">
                    {isInstalled ? 'App is installed' : 'Not installed'}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {isInstalled
                      ? 'Running in standalone mode'
                      : 'Install for a native app-like experience'}
                  </p>
                </div>
              </div>
              {isInstallable && !isInstalled && (
                <Button size="sm" onClick={install}>
                  <Download className="mr-1.5 h-3.5 w-3.5" />
                  Install
                </Button>
              )}
            </div>

            <div className="flex items-center justify-between rounded-lg border p-3">
              <div className="flex items-center gap-3">
                {swRegistered ? (
                  <CheckCircle className="h-5 w-5 text-success" />
                ) : (
                  <XCircle className="h-5 w-5 text-muted-foreground" />
                )}
                <div>
                  <p className="text-sm font-medium">Service Worker</p>
                  <p className="text-xs text-muted-foreground">
                    {swRegistered ? 'Registered and active' : 'Not registered'}
                  </p>
                </div>
              </div>
              <Badge variant={swRegistered ? 'default' : 'secondary'}>
                {swRegistered ? 'Active' : 'Inactive'}
              </Badge>
            </div>
          </CardContent>
        </Card>

        {/* Push Notifications */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-sm font-medium">
              <Bell className="h-4 w-4" /> Push Notifications
            </CardTitle>
            <CardDescription>
              Get notified about new leads, callbacks, and important updates
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex items-center justify-between rounded-lg border p-3">
              <div className="flex items-center gap-3">
                {pushSubscribed ? (
                  <Bell className="h-5 w-5 text-success" />
                ) : (
                  <Bell className="h-5 w-5 text-muted-foreground" />
                )}
                <div>
                  <p className="text-sm font-medium">
                    {pushSubscribed ? 'Notifications enabled' : 'Notifications disabled'}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {pushSubscribed
                      ? 'You will receive push notifications'
                      : 'Enable to receive push notifications on your device'}
                  </p>
                </div>
              </div>
              {pushSubscribed ? (
                <Button
                  size="sm"
                  variant="outline"
                  onClick={handleUnsubscribePush}
                  disabled={pushLoading}
                >
                  {pushLoading ? <RefreshCw className="h-3.5 w-3.5 animate-spin" /> : 'Disable'}
                </Button>
              ) : (
                <Button size="sm" onClick={handleSubscribePush} disabled={pushLoading}>
                  {pushLoading ? <RefreshCw className="h-3.5 w-3.5 animate-spin" /> : 'Enable'}
                </Button>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Offline Data */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-sm font-medium">
              <Database className="h-4 w-4" /> Offline Data
            </CardTitle>
            <CardDescription>
              Cached lead data is available when you lose internet connection
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between rounded-lg border p-3">
              <div className="flex items-center gap-3">
                {isOnline ? (
                  <Wifi className="h-5 w-5 text-success" />
                ) : (
                  <WifiOff className="h-5 w-5 text-warning" />
                )}
                <div>
                  <p className="text-sm font-medium">{isOnline ? 'Online' : 'Offline'}</p>
                  <p className="text-xs text-muted-foreground">
                    {isOnline
                      ? 'Your leads are being cached for offline access'
                      : 'Using cached data — some features may be limited'}
                  </p>
                </div>
              </div>
              <Badge variant={isOnline ? 'default' : 'secondary'}>
                {isOnline ? 'Connected' : 'Disconnected'}
              </Badge>
            </div>

            <div className="flex items-center justify-between rounded-lg border p-3">
              <div className="flex items-center gap-3">
                <Database className="h-5 w-5 text-info" />
                <div>
                  <p className="text-sm font-medium">Cached Leads</p>
                  <p className="text-xs text-muted-foreground">
                    {cachedLeadsCount} lead(s) cached
                    {lastCached &&
                      ` • Last updated ${new Date(lastCached).toLocaleString('en-PH')}`}
                  </p>
                </div>
              </div>
              <Button
                size="sm"
                variant="outline"
                onClick={handleClearCache}
                disabled={clearing || cachedLeadsCount === 0}
              >
                {clearing ? (
                  <RefreshCw className="h-3.5 w-3.5 animate-spin" />
                ) : (
                  <Trash2 className="h-3.5 w-3.5" />
                )}
                Clear
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </AgentLayout>
  );
}
