import { Head } from '@inertiajs/react';
import { useState, useRef, useEffect, useCallback } from 'react';
import {
  ScanLine, Volume2, VolumeX, Wifi, WifiOff,
  CheckCircle2, XCircle, AlertTriangle, Clock,
  RotateCcw, Trash2,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

// ─── Types ────────────────────────────────────────────────────────────────────

type ScanStatus =
  | 'ok'
  | 'unknown'
  | 'duplicate'
  | 'beyond_sla'
  | 'wrong_status'
  | 'already_processed'
  | 'offline_queued'
  | 'error';

type ScanMode = 'validate' | 'dispatch' | 'receive_return';

interface ScanEntry {
  id: string;
  waybill_number: string;
  status: ScanStatus;
  message: string;
  receiver_name?: string;
  city?: string;
  scanned_at: number;
  undoable: boolean;
}

// ─── Utilities ────────────────────────────────────────────────────────────────

function uuid(): string {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

function getCsrf(): string {
  return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

// ─── Web Audio Beeps (no MP3 files) ──────────────────────────────────────────

let _audioCtx: AudioContext | null = null;

function getAudioCtx(): AudioContext {
  if (!_audioCtx) _audioCtx = new AudioContext();
  return _audioCtx;
}

function tone(
  ctx: AudioContext,
  freq: number,
  start: number,
  duration: number,
  type: OscillatorType = 'sine',
  gain = 0.25,
) {
  const osc = ctx.createOscillator();
  const g = ctx.createGain();
  osc.type = type;
  osc.frequency.value = freq;
  g.gain.setValueAtTime(gain, ctx.currentTime + start);
  g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + start + duration);
  osc.connect(g);
  g.connect(ctx.destination);
  osc.start(ctx.currentTime + start);
  osc.stop(ctx.currentTime + start + duration + 0.01);
}

function playBeep(type: 'ok' | 'warning' | 'error') {
  try {
    const ctx = getAudioCtx();
    if (ctx.state === 'suspended') ctx.resume();
    if (type === 'ok') {
      tone(ctx, 1046, 0, 0.07);
    } else if (type === 'warning') {
      tone(ctx, 880, 0, 0.09);
      tone(ctx, 660, 0.12, 0.09);
    } else {
      tone(ctx, 220, 0, 0.22, 'sawtooth');
    }
  } catch {
    // Audio blocked or not supported
  }
}

// ─── IndexedDB offline queue ──────────────────────────────────────────────────

const IDB_DB = 'waybill-scanner-offline';
const IDB_STORE = 'queue';

function openIDB(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(IDB_DB, 1);
    req.onupgradeneeded = (e) => {
      const db = (e.target as IDBOpenDBRequest).result;
      if (!db.objectStoreNames.contains(IDB_STORE)) {
        db.createObjectStore(IDB_STORE, { keyPath: 'id' });
      }
    };
    req.onsuccess = (e) => resolve((e.target as IDBOpenDBRequest).result);
    req.onerror = () => reject(req.error);
  });
}

async function idbPush(item: { id: string; waybill_number: string; session_id: string; mode: ScanMode }) {
  const db = await openIDB();
  await new Promise<void>((resolve, reject) => {
    const tx = db.transaction(IDB_STORE, 'readwrite');
    tx.objectStore(IDB_STORE).add(item);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
}

async function idbAll(): Promise<Array<{ id: string; waybill_number: string; session_id: string; mode: ScanMode }>> {
  const db = await openIDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(IDB_STORE, 'readonly');
    const req = tx.objectStore(IDB_STORE).getAll();
    req.onsuccess = () => resolve(req.result ?? []);
    req.onerror = () => reject(req.error);
  });
}

async function idbRemove(id: string) {
  const db = await openIDB();
  await new Promise<void>((resolve, reject) => {
    const tx = db.transaction(IDB_STORE, 'readwrite');
    tx.objectStore(IDB_STORE).delete(id);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
}

// ─── Constants ────────────────────────────────────────────────────────────────

const HISTORY_MAX = 50;
const UNDO_MS = 30_000;

const MODE_LABELS: Record<ScanMode, string> = {
  validate: 'Validate',
  dispatch: 'Dispatch',
  receive_return: 'Receive Return',
};

const STATUS_META: Record<ScanStatus, {
  label: string;
  rowCls: string;
  badgeCls: string;
  icon: React.ReactNode;
  beep: 'ok' | 'warning' | 'error';
}> = {
  ok: {
    label: 'OK',
    rowCls: 'border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950',
    badgeCls: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
    icon: <CheckCircle2 className="h-4 w-4 text-green-600 shrink-0" />,
    beep: 'ok',
  },
  beyond_sla: {
    label: 'Beyond SLA',
    rowCls: 'border-orange-200 bg-orange-50 dark:border-orange-800 dark:bg-orange-950',
    badgeCls: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
    icon: <AlertTriangle className="h-4 w-4 text-orange-500 shrink-0" />,
    beep: 'warning',
  },
  duplicate: {
    label: 'Duplicate',
    rowCls: 'border-yellow-200 bg-yellow-50 dark:border-yellow-800 dark:bg-yellow-950',
    badgeCls: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
    icon: <AlertTriangle className="h-4 w-4 text-yellow-500 shrink-0" />,
    beep: 'warning',
  },
  already_processed: {
    label: 'Already Done',
    rowCls: 'border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950',
    badgeCls: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    icon: <CheckCircle2 className="h-4 w-4 text-blue-500 shrink-0" />,
    beep: 'warning',
  },
  unknown: {
    label: 'Unknown',
    rowCls: 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950',
    badgeCls: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    icon: <XCircle className="h-4 w-4 text-red-500 shrink-0" />,
    beep: 'error',
  },
  wrong_status: {
    label: 'Wrong Status',
    rowCls: 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950',
    badgeCls: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    icon: <XCircle className="h-4 w-4 text-red-500 shrink-0" />,
    beep: 'error',
  },
  offline_queued: {
    label: 'Queued',
    rowCls: 'border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900',
    badgeCls: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
    icon: <Clock className="h-4 w-4 text-gray-400 shrink-0" />,
    beep: 'warning',
  },
  error: {
    label: 'Error',
    rowCls: 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950',
    badgeCls: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    icon: <XCircle className="h-4 w-4 text-red-500 shrink-0" />,
    beep: 'error',
  },
};

// ─── Component ────────────────────────────────────────────────────────────────

export default function ScannerIndex() {
  const [mode, setMode] = useState<ScanMode>('validate');
  const [soundEnabled, setSoundEnabled] = useState(true);
  const [isOnline, setIsOnline] = useState(() =>
    typeof navigator !== 'undefined' ? navigator.onLine : true
  );
  const [history, setHistory] = useState<ScanEntry[]>([]);
  const [queueSize, setQueueSize] = useState(0);
  const [offlinePending, setOfflinePending] = useState(0);
  const [inputValue, setInputValue] = useState('');
  const sessionId = useRef(uuid());
  const inputRef = useRef<HTMLInputElement>(null);
  const keystrokeTimesRef = useRef<number[]>([]);
  const queue = useRef<string[]>([]);
  const processing = useRef(false);
  const undoTimers = useRef<Record<string, ReturnType<typeof setTimeout>>>({});
  const undoTick = useRef<ReturnType<typeof setInterval> | null>(null);
  const modeRef = useRef(mode);
  const soundRef = useRef(soundEnabled);

  useEffect(() => { modeRef.current = mode; }, [mode]);
  useEffect(() => { soundRef.current = soundEnabled; }, [soundEnabled]);

  // Persistent focus — re-focus whenever anything is clicked
  useEffect(() => {
    const refocus = () => setTimeout(() => inputRef.current?.focus(), 50);
    document.addEventListener('click', refocus);
    inputRef.current?.focus();
    return () => document.removeEventListener('click', refocus);
  }, []);

  // Online / offline
  useEffect(() => {
    const onOnline = () => { setIsOnline(true); drainOffline(); };
    const onOffline = () => setIsOnline(false);
    window.addEventListener('online', onOnline);
    window.addEventListener('offline', onOffline);
    return () => {
      window.removeEventListener('online', onOnline);
      window.removeEventListener('offline', onOffline);
    };
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  // Load offline pending count on mount
  useEffect(() => {
    idbAll().then((items) => setOfflinePending(items.length)).catch(() => {});
  }, []);

  // Undo countdown tick (updates every second while undoable items exist)
  const startUndoTick = useCallback(() => {
    if (undoTick.current) return;
    undoTick.current = setInterval(() => {
      const now = Date.now();
      setHistory((prev) => {
        const updated = prev.map((item) =>
          item.undoable && now - item.scanned_at >= UNDO_MS
            ? { ...item, undoable: false }
            : item
        );
        // Stop tick if no more undoable items
        if (!updated.some((i) => i.undoable)) {
          if (undoTick.current) clearInterval(undoTick.current);
          undoTick.current = null;
        }
        return updated;
      });
    }, 1000);
  }, []);

  useEffect(() => () => {
    if (undoTick.current) clearInterval(undoTick.current);
    Object.values(undoTimers.current).forEach(clearTimeout);
  }, []);

  const pushHistory = useCallback((entry: ScanEntry) => {
    setHistory((prev) => [entry, ...prev].slice(0, HISTORY_MAX));
    if (entry.undoable) startUndoTick();
  }, [startUndoTick]);

  const undoEntry = useCallback((id: string) => {
    clearTimeout(undoTimers.current[id]);
    delete undoTimers.current[id];
    setHistory((prev) => prev.filter((item) => item.id !== id));
  }, []);

  const callApi = useCallback(async (waybillNumber: string): Promise<ScanEntry> => {
    const res = await fetch('/waybills/scan', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': getCsrf(),
      },
      body: JSON.stringify({
        waybill_number: waybillNumber,
        session_id: sessionId.current,
        mode: modeRef.current,
      }),
    });

    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();

    const status = (data.status as ScanStatus) in STATUS_META
      ? (data.status as ScanStatus)
      : 'error';

    return {
      id: uuid(),
      waybill_number: data.waybill_number ?? waybillNumber,
      status,
      message: data.message ?? '',
      receiver_name: data.waybill?.receiver_name,
      city: data.waybill?.city,
      scanned_at: Date.now(),
      undoable: true,
    };
  }, []);

  const processQueue = useCallback(async () => {
    if (processing.current || queue.current.length === 0) return;
    processing.current = true;

    const number = queue.current.shift()!;
    setQueueSize(queue.current.length);

    let entry: ScanEntry;

    if (!navigator.onLine) {
      const offId = uuid();
      try {
        await idbPush({ id: offId, waybill_number: number, session_id: sessionId.current, mode: modeRef.current });
        setOfflinePending((n) => n + 1);
      } catch { /* IndexedDB may be unavailable */ }
      entry = {
        id: offId,
        waybill_number: number,
        status: 'offline_queued',
        message: 'No connection — queued for retry.',
        scanned_at: Date.now(),
        undoable: false,
      };
      if (soundRef.current) playBeep('warning');
    } else {
      try {
        entry = await callApi(number);
        const meta = STATUS_META[entry.status];
        if (soundRef.current) playBeep(meta.beep);
      } catch {
        entry = {
          id: uuid(),
          waybill_number: number,
          status: 'error',
          message: 'Network error — scan not recorded.',
          scanned_at: Date.now(),
          undoable: false,
        };
        if (soundRef.current) playBeep('error');
      }
    }

    pushHistory(entry);
    processing.current = false;

    if (queue.current.length > 0) {
      // Tiny yield so React can re-render before next API call
      setTimeout(processQueue, 0);
    }
  }, [callApi, pushHistory]);

  const enqueue = useCallback((raw: string) => {
    const number = raw.trim().toUpperCase();
    if (!number) return;
    queue.current.push(number);
    setQueueSize(queue.current.length);
    processQueue();
  }, [processQueue]);

  const drainOffline = useCallback(async () => {
    try {
      const items = await idbAll();
      for (const item of items) {
        if (!navigator.onLine) break;
        try {
          const entry = await callApi(item.waybill_number);
          const meta = STATUS_META[entry.status];
          if (soundRef.current) playBeep(meta.beep);
          pushHistory(entry);
          await idbRemove(item.id);
          setOfflinePending((n) => Math.max(0, n - 1));
        } catch {
          break;
        }
      }
    } catch { /* IndexedDB unavailable */ }
  }, [callApi, pushHistory]);

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      keystrokeTimesRef.current = [];
      const value = inputValue;
      setInputValue('');
      enqueue(value);
    } else {
      keystrokeTimesRef.current.push(Date.now());
    }
  };

  const stats = {
    ok: history.filter((h) => h.status === 'ok' || h.status === 'already_processed').length,
    warning: history.filter((h) => h.status === 'duplicate' || h.status === 'beyond_sla').length,
    error: history.filter((h) => h.status === 'unknown' || h.status === 'wrong_status' || h.status === 'error').length,
  };

  return (
    <AppLayout>
      <Head title="Scanner" />

      <div className="space-y-4">
        {/* Header */}
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex-1 min-w-0">
            <h1 className="text-2xl font-bold tracking-tight">Waybill Scanner</h1>
            <div className="flex items-center gap-2 mt-0.5 text-sm text-muted-foreground">
              <span>
                Session <span className="font-mono text-xs">{sessionId.current.slice(0, 8)}</span>
              </span>
              {isOnline ? (
                <span className="flex items-center gap-1 text-green-600">
                  <Wifi className="h-3 w-3" /> Online
                </span>
              ) : (
                <span className="flex items-center gap-1 text-orange-500">
                  <WifiOff className="h-3 w-3" /> Offline — scans queued
                </span>
              )}
              {offlinePending > 0 && isOnline && (
                <span className="text-blue-500 text-xs">
                  {offlinePending} offline scan(s) being submitted…
                </span>
              )}
            </div>
          </div>

          {/* Mode toggle */}
          <div className="flex rounded-lg border overflow-hidden text-sm">
            {(['validate', 'dispatch', 'receive_return'] as ScanMode[]).map((m) => (
              <button
                key={m}
                onClick={() => setMode(m)}
                className={cn(
                  'px-3 py-1.5 transition-colors',
                  mode === m
                    ? 'bg-primary text-primary-foreground font-medium'
                    : 'bg-background text-muted-foreground hover:bg-muted'
                )}
              >
                {MODE_LABELS[m]}
              </button>
            ))}
          </div>

          <Button
            variant="outline"
            size="icon"
            onClick={() => setSoundEnabled((v) => !v)}
            title={soundEnabled ? 'Mute beeps' : 'Enable beeps'}
          >
            {soundEnabled ? <Volume2 className="h-4 w-4" /> : <VolumeX className="h-4 w-4" />}
          </Button>
        </div>

        {/* Stats row */}
        <div className="grid grid-cols-4 gap-3">
          {[
            { label: 'OK', value: stats.ok, cls: 'text-green-600' },
            { label: 'Warning', value: stats.warning, cls: 'text-orange-500' },
            { label: 'Error', value: stats.error, cls: 'text-red-600' },
            { label: 'Pending', value: queueSize, cls: 'text-blue-500' },
          ].map((s) => (
            <Card key={s.label}>
              <CardContent className="flex items-center justify-between px-4 py-3">
                <span className="text-sm text-muted-foreground">{s.label}</span>
                <span className={cn('text-2xl font-bold tabular-nums', s.cls)}>{s.value}</span>
              </CardContent>
            </Card>
          ))}
        </div>

        {/* Scan input */}
        <Card>
          <CardContent className="pt-4 pb-4">
            <div className="flex items-center gap-3">
              <ScanLine className="h-5 w-5 text-muted-foreground shrink-0" />
              <input
                ref={inputRef}
                type="text"
                value={inputValue}
                onChange={(e) => setInputValue(e.target.value)}
                onKeyDown={handleKeyDown}
                onBlur={() => setTimeout(() => inputRef.current?.focus(), 80)}
                placeholder={`Scan or enter waybill — mode: ${MODE_LABELS[mode]}`}
                className="flex-1 h-12 rounded-lg border-2 border-primary/20 bg-background px-4 font-mono text-base placeholder:text-muted-foreground focus-visible:outline-none focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-ring"
                autoComplete="off"
                spellCheck={false}
                autoFocus
              />
            </div>
            <p className="mt-1.5 ml-8 text-xs text-muted-foreground">
              Input auto-refocuses after each scan. Hardware scanners submit on Enter automatically.
            </p>
          </CardContent>
        </Card>

        {/* Scan history */}
        <Card>
          <CardHeader className="pb-2 flex-row items-center justify-between space-y-0">
            <CardTitle className="text-base font-semibold">
              Scan History
              <span className="ml-1.5 text-sm font-normal text-muted-foreground">
                ({history.length} / {HISTORY_MAX})
              </span>
            </CardTitle>
            {history.length > 0 && (
              <Button
                variant="ghost"
                size="sm"
                className="h-7 text-xs"
                onClick={() => {
                  Object.values(undoTimers.current).forEach(clearTimeout);
                  undoTimers.current = {};
                  if (undoTick.current) { clearInterval(undoTick.current); undoTick.current = null; }
                  setHistory([]);
                }}
              >
                <Trash2 className="mr-1 h-3.5 w-3.5" />
                Clear
              </Button>
            )}
          </CardHeader>
          <CardContent className="pt-0">
            {history.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-10 text-muted-foreground">
                <ScanLine className="h-10 w-10 opacity-25 mb-3" />
                <p>No scans yet. Start scanning waybills to see results here.</p>
              </div>
            ) : (
              <div className="space-y-1.5 max-h-[520px] overflow-y-auto pr-0.5">
                {history.map((item) => {
                  const meta = STATUS_META[item.status] ?? STATUS_META.error;
                  const secsLeft = item.undoable
                    ? Math.max(0, Math.ceil((UNDO_MS - (Date.now() - item.scanned_at)) / 1000))
                    : 0;
                  return (
                    <div
                      key={item.id}
                      className={cn('flex items-center gap-3 rounded-lg border px-3 py-2', meta.rowCls)}
                    >
                      {meta.icon}
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                          <span className="font-mono font-semibold text-sm">{item.waybill_number}</span>
                          <span className={cn('inline-flex rounded-full px-2 py-0.5 text-xs font-medium', meta.badgeCls)}>
                            {meta.label}
                          </span>
                        </div>
                        {(item.receiver_name || item.city) && (
                          <p className="text-xs text-muted-foreground truncate">
                            {[item.receiver_name, item.city].filter(Boolean).join(' · ')}
                          </p>
                        )}
                        {item.message && item.status !== 'ok' && item.status !== 'already_processed' && (
                          <p className="text-xs text-muted-foreground">{item.message}</p>
                        )}
                      </div>
                      <div className="flex items-center gap-2 shrink-0">
                        <span className="text-xs text-muted-foreground tabular-nums">
                          {new Date(item.scanned_at).toLocaleTimeString('en-PH', {
                            hour: '2-digit', minute: '2-digit', second: '2-digit',
                          })}
                        </span>
                        {item.undoable && secsLeft > 0 && (
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-7 px-2 text-xs border-orange-300 text-orange-600 hover:bg-orange-50 dark:hover:bg-orange-950"
                            onClick={(e) => { e.stopPropagation(); undoEntry(item.id); }}
                            title="Remove from history (client-side only)"
                          >
                            <RotateCcw className="h-3 w-3 mr-1" />
                            {secsLeft}s
                          </Button>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
