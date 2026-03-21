import { Head } from '@inertiajs/react';
import { useState, useRef, useEffect } from 'react';
import {
  QrCode,
  Package,
  CheckCircle,
  XCircle,
  Trash2,
  Send,
  Volume2,
  VolumeX,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

interface ScannedItem {
  id: string;
  waybill_number: string;
  status: 'valid' | 'invalid' | 'duplicate';
  message?: string;
  receiver_name?: string;
  timestamp: Date;
}

// UUID generator with fallback for older browsers
function generateId(): string {
  // Fallback implementation that works in all browsers
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

export default function ScannerIndex() {
  const [scannedItems, setScannedItems] = useState<ScannedItem[]>([]);
  const [inputValue, setInputValue] = useState('');
  const [soundEnabled, setSoundEnabled] = useState(true);
  const [isProcessing, setIsProcessing] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  // Focus input on mount and after each scan
  useEffect(() => {
    inputRef.current?.focus();
  }, [scannedItems]);

  const playSound = (type: 'success' | 'error') => {
    if (!soundEnabled) return;
    // In production, use actual audio files
    const audio = new Audio(type === 'success' ? '/sounds/beep-success.mp3' : '/sounds/beep-error.mp3');
    audio.play().catch(() => {});
  };

  const handleScan = async (value: string) => {
    if (!value.trim() || isProcessing) return;

    setIsProcessing(true);
    const waybillNumber = value.trim().toUpperCase();

    // Check for duplicates
    if (scannedItems.some(item => item.waybill_number === waybillNumber)) {
      playSound('error');
      setScannedItems(prev => [{
        id: generateId(),
        waybill_number: waybillNumber,
        status: 'duplicate',
        message: 'Already scanned',
        timestamp: new Date(),
      }, ...prev]);
      setInputValue('');
      setIsProcessing(false);
      return;
    }

    // Simulate API validation (replace with actual API call)
    try {
      // In production: const response = await fetch(`/api/waybills/validate/${waybillNumber}`);
      const isValid = waybillNumber.length >= 8; // Simple validation

      if (isValid) {
        playSound('success');
        setScannedItems(prev => [{
          id: generateId(),
          waybill_number: waybillNumber,
          status: 'valid',
          receiver_name: 'Sample Receiver', // Would come from API
          timestamp: new Date(),
        }, ...prev]);
      } else {
        playSound('error');
        setScannedItems(prev => [{
          id: generateId(),
          waybill_number: waybillNumber,
          status: 'invalid',
          message: 'Waybill not found',
          timestamp: new Date(),
        }, ...prev]);
      }
    } catch {
      playSound('error');
      setScannedItems(prev => [{
        id: generateId(),
        waybill_number: waybillNumber,
        status: 'invalid',
        message: 'Validation error',
        timestamp: new Date(),
      }, ...prev]);
    }

    setInputValue('');
    setIsProcessing(false);
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      handleScan(inputValue);
    }
  };

  const removeItem = (id: string) => {
    setScannedItems(prev => prev.filter(item => item.id !== id));
  };

  const clearAll = () => {
    setScannedItems([]);
  };

  const validCount = scannedItems.filter(i => i.status === 'valid').length;
  const invalidCount = scannedItems.filter(i => i.status !== 'valid').length;

  const handleDispatch = async () => {
    const validWaybills = scannedItems.filter(i => i.status === 'valid');
    if (validWaybills.length === 0) return;

    // In production: POST to /api/waybills/dispatch
    alert(`Dispatching ${validWaybills.length} waybills`);
  };

  return (
    <AppLayout>
      <Head title="Scanner" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Waybill Scanner</h1>
            <p className="text-muted-foreground">
              Scan waybills for batch dispatch processing
            </p>
          </div>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="icon"
              onClick={() => setSoundEnabled(!soundEnabled)}
            >
              {soundEnabled ? <Volume2 className="h-4 w-4" /> : <VolumeX className="h-4 w-4" />}
            </Button>
            <Button variant="outline" onClick={clearAll} disabled={scannedItems.length === 0}>
              <Trash2 className="mr-2 h-4 w-4" />
              Clear All
            </Button>
            <Button onClick={handleDispatch} disabled={validCount === 0}>
              <Send className="mr-2 h-4 w-4" />
              Dispatch ({validCount})
            </Button>
          </div>
        </div>

        <div className="grid gap-6 lg:grid-cols-3">
          {/* Scanner Input */}
          <Card className="lg:col-span-2">
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <QrCode className="h-5 w-5" />
                Scan Input
              </CardTitle>
              <CardDescription>
                Scan barcode or enter waybill number manually
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="relative">
                <Package className="absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-muted-foreground" />
                <input
                  ref={inputRef}
                  type="text"
                  value={inputValue}
                  onChange={(e) => setInputValue(e.target.value)}
                  onKeyDown={handleKeyDown}
                  placeholder="Scan or enter waybill number..."
                  className="flex h-14 w-full rounded-lg border-2 border-primary/20 bg-background pl-12 pr-4 text-lg font-mono ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-ring"
                  autoComplete="off"
                  autoFocus
                />
              </div>
              <p className="mt-2 text-sm text-muted-foreground">
                Press Enter to submit manually entered waybill numbers
              </p>
            </CardContent>
          </Card>

          {/* Stats */}
          <div className="space-y-4">
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium">Session Stats</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Total Scanned</span>
                  <span className="text-2xl font-bold">{scannedItems.length}</span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="flex items-center gap-2 text-green-600">
                    <CheckCircle className="h-4 w-4" /> Valid
                  </span>
                  <span className="text-xl font-semibold text-green-600">{validCount}</span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="flex items-center gap-2 text-red-600">
                    <XCircle className="h-4 w-4" /> Invalid/Duplicate
                  </span>
                  <span className="text-xl font-semibold text-red-600">{invalidCount}</span>
                </div>
              </CardContent>
            </Card>
          </div>
        </div>

        {/* Scanned Items List */}
        <Card>
          <CardHeader>
            <CardTitle>Scanned Items</CardTitle>
            <CardDescription>
              Recent scans appear at the top
            </CardDescription>
          </CardHeader>
          <CardContent>
            {scannedItems.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <QrCode className="h-12 w-12 text-muted-foreground/50" />
                <p className="mt-4 text-lg font-medium text-muted-foreground">No items scanned yet</p>
                <p className="text-sm text-muted-foreground">Start scanning waybills to see them here</p>
              </div>
            ) : (
              <div className="space-y-2 max-h-[400px] overflow-y-auto">
                {scannedItems.map((item) => (
                  <div
                    key={item.id}
                    className={`flex items-center justify-between rounded-lg border p-3 ${
                      item.status === 'valid'
                        ? 'border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-950'
                        : item.status === 'duplicate'
                        ? 'border-yellow-200 bg-yellow-50 dark:border-yellow-900 dark:bg-yellow-950'
                        : 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950'
                    }`}
                  >
                    <div className="flex items-center gap-3">
                      {item.status === 'valid' ? (
                        <CheckCircle className="h-5 w-5 text-green-600" />
                      ) : (
                        <XCircle className="h-5 w-5 text-red-600" />
                      )}
                      <div>
                        <div className="font-mono font-medium">{item.waybill_number}</div>
                        {item.receiver_name && (
                          <div className="text-sm text-muted-foreground">{item.receiver_name}</div>
                        )}
                        {item.message && (
                          <div className="text-sm text-red-600">{item.message}</div>
                        )}
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      <Badge
                        variant={
                          item.status === 'valid'
                            ? 'default'
                            : item.status === 'duplicate'
                            ? 'secondary'
                            : 'destructive'
                        }
                      >
                        {item.status}
                      </Badge>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8"
                        onClick={() => removeItem(item.id)}
                      >
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
