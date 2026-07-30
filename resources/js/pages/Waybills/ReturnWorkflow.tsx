import { useEffect, useState, useRef, useCallback } from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  Package,
  PackageCheck,
  AlertTriangle,
  Wallet,
  Loader2,
  ScanLine,
  CheckCircle2,
  XCircle,
  Clock,
  Warehouse,
  Bell,
} from 'lucide-react';
import { toast } from 'sonner';
import axios from 'axios';
import { formatDate } from '@/lib/utils';

interface Receipt {
  id: number;
  waybill_id: number;
  condition: string;
  notes: string | null;
  scanned_at: string | null;
  processed_at: string | null;
  inventory_updated: boolean;
  finance_notified: boolean;
  scanned_by: string | null;
  waybill: {
    id: number;
    waybill_number: string;
    status: string;
    courier: string;
    receiver_name: string;
    city: string;
    cod_amount: number;
    item_name: string;
    item_qty: number;
    returned_at: string | null;
  } | null;
}

interface Summary {
  total_received: number;
  today_count: number;
  pending_inventory: number;
  pending_finance: number;
  damaged_count: number;
  cod_at_risk: number;
  pending_returns: number;
}

interface PendingWaybill {
  id: number;
  waybill_number: string;
  status: string;
  courier: string;
  receiver_name: string;
  city: string;
  cod_amount: number;
  item_name: string;
  item_qty: number;
  returned_at: string | null;
}

interface Props {
  summary: Summary;
  receipts: Receipt[];
  pending: PendingWaybill[];
  filters: { from: string; to: string; condition: string | null };
}

function formatPeso(amount: number): string {
  return (
    '₱' +
    Number(amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  );
}

export default function ReturnWorkflow({ summary, receipts, pending }: Props) {
  const [scanInput, setScanInput] = useState('');
  const [condition, setCondition] = useState('GOOD');
  const [notes, setNotes] = useState('');
  const [scanning, setScanning] = useState(false);
  const [scanResults, setScanResults] = useState<
    { status: string; message: string; waybill_number?: string; waybill?: any }[]
  >([]);
  const [liveData, setLiveData] = useState({ summary, receipts, pending });
  const [loading, setLoading] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const refreshData = useCallback(() => {
    setLoading(true);
    axios
      .get('/waybills/returns/api')
      .then(({ data }) => {
        setLiveData({ summary: data.summary, receipts: data.receipts, pending: data.pending });
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    inputRef.current?.focus();
  }, []);

  function handleScan(e: React.FormEvent) {
    e.preventDefault();
    const number = scanInput.trim();
    if (!number) return;

    setScanning(true);
    axios
      .post('/waybills/returns/scan', {
        waybill_number: number,
        condition,
        notes: notes || undefined,
      })
      .then(({ data }) => {
        setScanResults((prev) => [{ ...data, waybill_number: number }, ...prev].slice(0, 20));

        if (data.status === 'success') {
          toast.success(`Received: ${number}`, {
            description: data.message,
          });
          refreshData();
        } else if (data.status === 'already_processed') {
          toast.warning(`Already received: ${number}`);
        } else if (data.status === 'wrong_status') {
          toast.error(`Wrong status: ${number}`, { description: data.message });
        } else {
          toast.error(`Not found: ${number}`);
        }

        setScanInput('');
        inputRef.current?.focus();
      })
      .catch(() => {
        toast.error('Scan failed');
      })
      .finally(() => setScanning(false));
  }

  function handleBatchScan() {
    const numbers = scanInput
      .split(/[\r\n,\s]+/)
      .map((s) => s.trim())
      .filter(Boolean);

    if (numbers.length < 2) return;

    setScanning(true);
    axios
      .post('/waybills/returns/batch-scan', {
        waybill_numbers: numbers,
        condition,
        notes: notes || undefined,
      })
      .then(({ data }) => {
        setScanResults(
          data.results
            .map((r: any, i: number) => ({ ...r, waybill_number: numbers[i] }))
            .slice(0, 20)
        );
        toast.success(
          `Batch: ${data.summary.success} received, ${data.summary.not_found} not found, ${data.summary.already_processed} already done`
        );
        refreshData();
        setScanInput('');
        inputRef.current?.focus();
      })
      .catch(() => toast.error('Batch scan failed'))
      .finally(() => setScanning(false));
  }

  return (
    <AppLayout>
      <Head title="Return Workflow" />

      <div className="space-y-4 p-6">
        <div>
          <h1 className="text-xl font-bold font-display">Return Workflow</h1>
          <p className="text-sm text-muted-foreground">
            Scan returned parcels → create receipt → update inventory → notify finance
          </p>
        </div>

        {/* Stats Row */}
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-7">
          <StatChip
            icon={<PackageCheck className="h-4 w-4 text-success" />}
            label="Total Received"
            value={String(liveData.summary.total_received)}
          />
          <StatChip
            icon={<Clock className="h-4 w-4 text-info" />}
            label="Today"
            value={String(liveData.summary.today_count)}
          />
          <StatChip
            icon={<Warehouse className="h-4 w-4 text-warning" />}
            label="Pending Inventory"
            value={String(liveData.summary.pending_inventory)}
          />
          <StatChip
            icon={<Bell className="h-4 w-4 text-warning" />}
            label="Pending Finance"
            value={String(liveData.summary.pending_finance)}
          />
          <StatChip
            icon={<AlertTriangle className="h-4 w-4 text-destructive" />}
            label="Damaged"
            value={String(liveData.summary.damaged_count)}
          />
          <StatChip
            icon={<Wallet className="h-4 w-4 text-destructive" />}
            label="COD at Risk"
            value={formatPeso(liveData.summary.cod_at_risk)}
          />
          <StatChip
            icon={<Package className="h-4 w-4 text-muted-foreground" />}
            label="Pending Returns"
            value={String(liveData.summary.pending_returns)}
          />
        </div>

        <div className="grid gap-4 lg:grid-cols-3">
          {/* Scan Panel */}
          <Card className="p-4 space-y-4">
            <div className="flex items-center gap-2">
              <ScanLine className="h-5 w-5 text-info" />
              <h2 className="text-sm font-semibold">Scan Return</h2>
            </div>

            <form onSubmit={handleScan} className="space-y-3">
              <Input
                ref={inputRef}
                value={scanInput}
                onChange={(e) => setScanInput(e.target.value)}
                placeholder="Scan or enter waybill number..."
                className="font-mono text-lg"
                disabled={scanning}
              />
              <div className="flex gap-2">
                <Select value={condition} onValueChange={setCondition}>
                  <SelectTrigger className="w-32">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="GOOD">Good</SelectItem>
                    <SelectItem value="DAMAGED">Damaged</SelectItem>
                  </SelectContent>
                </Select>
                <Button type="submit" disabled={scanning || !scanInput.trim()} className="flex-1">
                  {scanning ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Receive'}
                </Button>
              </div>
              <Textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                placeholder="Notes (optional)..."
                className="text-sm"
                rows={2}
              />
              {scanInput.includes(',') || scanInput.includes('\n') ? (
                <Button
                  type="button"
                  variant="outline"
                  className="w-full"
                  onClick={handleBatchScan}
                  disabled={scanning}
                >
                  Batch Scan ({scanInput.split(/[\r\n,\s]+/).filter(Boolean).length} items)
                </Button>
              ) : null}
            </form>

            {/* Scan Results */}
            {scanResults.length > 0 && (
              <div className="space-y-2 max-h-64 overflow-y-auto">
                <h3 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                  Recent Scans
                </h3>
                {scanResults.map((r, idx) => (
                  <div key={idx} className="flex items-start gap-2 text-sm border-b pb-2">
                    {r.status === 'success' ? (
                      <CheckCircle2 className="h-4 w-4 text-success flex-shrink-0 mt-0.5" />
                    ) : r.status === 'already_processed' ? (
                      <Clock className="h-4 w-4 text-warning flex-shrink-0 mt-0.5" />
                    ) : (
                      <XCircle className="h-4 w-4 text-destructive flex-shrink-0 mt-0.5" />
                    )}
                    <div className="flex-1 min-w-0">
                      <div className="font-mono text-xs font-medium">{r.waybill_number}</div>
                      <div className="text-xs text-muted-foreground truncate">{r.message}</div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Card>

          {/* Pending Returns */}
          <Card className="p-4 space-y-3">
            <div className="flex items-center justify-between">
              <h2 className="text-sm font-semibold">
                Awaiting Receipt ({liveData.pending.length})
              </h2>
              {loading && <Loader2 className="h-3 w-3 animate-spin text-muted-foreground" />}
            </div>
            <div className="space-y-2 max-h-[500px] overflow-y-auto">
              {liveData.pending.length === 0 ? (
                <p className="text-sm text-muted-foreground py-8 text-center">
                  No pending returns.
                </p>
              ) : (
                liveData.pending.map((w) => (
                  <div
                    key={w.id}
                    className="flex items-center justify-between gap-2 border-b pb-2 text-sm"
                  >
                    <div className="flex-1 min-w-0">
                      <Link
                        href={`/waybills/${w.id}`}
                        className="font-mono text-xs font-medium hover:underline"
                      >
                        {w.waybill_number}
                      </Link>
                      <div className="text-xs text-muted-foreground truncate">
                        {w.receiver_name} · {w.city || 'N/A'}
                      </div>
                      {w.returned_at && (
                        <div className="text-xs text-muted-foreground/60">
                          Returned {formatDate(w.returned_at)}
                        </div>
                      )}
                    </div>
                    <div className="text-right">
                      <div className="text-xs font-medium">{formatPeso(w.cod_amount)}</div>
                      <Badge variant="secondary" className="text-xs">
                        {w.courier}
                      </Badge>
                    </div>
                  </div>
                ))
              )}
            </div>
          </Card>

          {/* Recent Receipts */}
          <Card className="p-4 space-y-3">
            <div className="flex items-center justify-between">
              <h2 className="text-sm font-semibold">Recent Receipts</h2>
              {loading && <Loader2 className="h-3 w-3 animate-spin text-muted-foreground" />}
            </div>
            <div className="space-y-2 max-h-[500px] overflow-y-auto">
              {liveData.receipts.length === 0 ? (
                <p className="text-sm text-muted-foreground py-8 text-center">No receipts yet.</p>
              ) : (
                liveData.receipts.slice(0, 30).map((r) => (
                  <div key={r.id} className="border-b pb-2 text-sm space-y-1">
                    <div className="flex items-center justify-between">
                      <Link
                        href={`/waybills/${r.waybill_id}`}
                        className="font-mono text-xs font-medium hover:underline"
                      >
                        {r.waybill?.waybill_number}
                      </Link>
                      <Badge
                        variant="secondary"
                        className={
                          r.condition === 'DAMAGED'
                            ? 'text-xs bg-destructive/10 text-destructive'
                            : 'text-xs bg-success/10 text-success'
                        }
                      >
                        {r.condition}
                      </Badge>
                    </div>
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                      <span className="flex items-center gap-0.5">
                        <Warehouse className="h-3 w-3" />
                        {r.inventory_updated ? (
                          <span className="text-success">Inventory ✓</span>
                        ) : (
                          <span className="text-muted-foreground">Inventory —</span>
                        )}
                      </span>
                      <span className="flex items-center gap-0.5">
                        <Bell className="h-3 w-3" />
                        {r.finance_notified ? (
                          <span className="text-success">Finance ✓</span>
                        ) : (
                          <span className="text-muted-foreground">Finance —</span>
                        )}
                      </span>
                    </div>
                    <div className="text-xs text-muted-foreground/60">
                      {r.scanned_by} · {r.scanned_at && formatDate(r.scanned_at)}
                    </div>
                  </div>
                ))
              )}
            </div>
          </Card>
        </div>

        {/* Full Receipts Table */}
        <Card className="p-4 space-y-3">
          <h2 className="text-sm font-semibold">All Receipts ({liveData.receipts.length})</h2>
          {liveData.receipts.length === 0 ? (
            <p className="text-sm text-muted-foreground py-8 text-center">No receipts found.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Waybill #</TableHead>
                  <TableHead>Courier</TableHead>
                  <TableHead>Receiver</TableHead>
                  <TableHead>Condition</TableHead>
                  <TableHead className="text-right">COD</TableHead>
                  <TableHead>Inventory</TableHead>
                  <TableHead>Finance</TableHead>
                  <TableHead>Scanned By</TableHead>
                  <TableHead>Scanned At</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {liveData.receipts.map((r) => (
                  <TableRow key={r.id}>
                    <TableCell>
                      <Link
                        href={`/waybills/${r.waybill_id}`}
                        className="font-mono text-sm font-medium hover:underline"
                      >
                        {r.waybill?.waybill_number}
                      </Link>
                    </TableCell>
                    <TableCell className="text-sm">{r.waybill?.courier}</TableCell>
                    <TableCell className="text-sm">{r.waybill?.receiver_name}</TableCell>
                    <TableCell>
                      <Badge
                        variant="secondary"
                        className={
                          r.condition === 'DAMAGED'
                            ? 'text-xs bg-destructive/10 text-destructive'
                            : 'text-xs bg-success/10 text-success'
                        }
                      >
                        {r.condition}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right text-sm font-medium">
                      {formatPeso(r.waybill?.cod_amount ?? 0)}
                    </TableCell>
                    <TableCell>
                      {r.inventory_updated ? (
                        <CheckCircle2 className="h-4 w-4 text-success" />
                      ) : (
                        <XCircle className="h-4 w-4 text-muted-foreground" />
                      )}
                    </TableCell>
                    <TableCell>
                      {r.finance_notified ? (
                        <CheckCircle2 className="h-4 w-4 text-success" />
                      ) : (
                        <XCircle className="h-4 w-4 text-muted-foreground" />
                      )}
                    </TableCell>
                    <TableCell className="text-sm">{r.scanned_by}</TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {r.scanned_at && formatDate(r.scanned_at)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </Card>
      </div>
    </AppLayout>
  );
}

function StatChip({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
  return (
    <Card className="p-3 flex items-center gap-2">
      {icon}
      <div>
        <div className="text-lg font-bold font-display leading-tight">{value}</div>
        <div className="text-xs text-muted-foreground">{label}</div>
      </div>
    </Card>
  );
}
