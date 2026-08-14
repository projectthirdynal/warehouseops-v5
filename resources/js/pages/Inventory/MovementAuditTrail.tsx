import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  Activity,
  ArrowLeft,
  ArrowRight,
  Building2,
  Calendar,
  Filter,
  Package,
  RotateCcw,
  Search,
  User,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { formatDate } from '@/lib/utils';

interface AuditRow {
  id: number;
  type: string;
  quantity: number;
  before_quantity: number;
  after_quantity: number;
  before_reserved: number;
  after_reserved: number;
  reason_code?: string | null;
  reason_notes?: string | null;
  reference_type?: string | null;
  reference_id?: number | null;
  ip_address?: string | null;
  user_agent?: string | null;
  created_at: string;
  stockable?: {
    id: number;
    name: string;
    sku?: string | null;
  } | null;
  warehouse?: {
    id: number;
    name: string;
    code: string;
  } | null;
  performer?: {
    id: number;
    name: string;
  } | null;
}

interface PaginatedAudits {
  data: AuditRow[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  links: { url: string | null; label: string; active: boolean }[];
}

interface PageData {
  audits: PaginatedAudits;
  summary: Record<string, number>;
  filters: Record<string, string | undefined>;
}

interface Props {
  data: PageData;
  warehouses: { id: number; name: string; code: string }[];
  filters: Record<string, string | undefined>;
}

const movementTypeColors: Record<string, string> = {
  STOCK_IN: 'bg-success text-white',
  STOCK_OUT: 'bg-destructive text-white',
  ADJUSTMENT: 'bg-warning text-white',
  RESERVATION: 'bg-info text-white',
  RELEASE: 'bg-primary text-white',
  RETURN: 'bg-purple-500 text-white',
  WRITE_OFF: 'bg-muted text-white',
  TRANSFER: 'bg-orange-500 text-white',
};

export default function MovementAuditTrail({ data, warehouses, filters }: Props) {
  const [warehouseId, setWarehouseId] = useState(filters?.warehouse_id ?? 'all');
  const [movementType, setMovementType] = useState(filters?.movement_type ?? 'all');
  const [stream, setStream] = useState(filters?.stream ?? 'all');
  const [search, setSearch] = useState(filters?.search ?? '');
  const [backfilling, setBackfilling] = useState(false);

  const applyFilters = () => {
    router.get(
      '/inventory/audit-trail',
      {
        warehouse_id: warehouseId !== 'all' ? warehouseId : undefined,
        movement_type: movementType !== 'all' ? movementType : undefined,
        stream: stream !== 'all' ? stream : undefined,
        search: search || undefined,
      },
      { preserveState: true }
    );
  };

  const goToPage = (page: number) => {
    router.get(
      '/inventory/audit-trail',
      {
        warehouse_id: warehouseId !== 'all' ? warehouseId : undefined,
        movement_type: movementType !== 'all' ? movementType : undefined,
        stream: stream !== 'all' ? stream : undefined,
        search: search || undefined,
        page,
      },
      { preserveState: true }
    );
  };

  const handleBackfill = async () => {
    setBackfilling(true);
    try {
      const token =
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
      await fetch('/inventory/audit-trail/backfill', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': token },
      });
      router.reload();
    } catch {
      // ignore
    } finally {
      setBackfilling(false);
    }
  };

  const summary = data.summary ?? {};

  return (
    <AppLayout>
      <Head title="Movement Audit Trail" />
      <div className="space-y-6">
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-xl font-bold font-display tracking-tight flex items-center gap-2">
              <Activity className="h-5 w-5 text-primary" />
              Movement Audit Trail
            </h1>
            <p className="text-muted-foreground">
              Complete stock movement history with before/after quantities, reason, and user
            </p>
          </div>
          <Button variant="outline" size="sm" onClick={handleBackfill} disabled={backfilling}>
            <RotateCcw className={`mr-1.5 h-4 w-4 ${backfilling ? 'animate-spin' : ''}`} />
            Backfill History
          </Button>
        </div>

        {/* Summary */}
        <div className="grid gap-4 md:grid-cols-4 lg:grid-cols-6">
          {Object.entries(summary).map(([type, count]) => (
            <Card key={type}>
              <CardHeader className="pb-2">
                <CardTitle className="text-xs font-medium text-muted-foreground uppercase">
                  {type.replace('_', ' ')}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold font-display">{count}</div>
              </CardContent>
            </Card>
          ))}
          {Object.keys(summary).length === 0 && (
            <Card className="md:col-span-4 lg:col-span-6">
              <CardContent className="py-4 text-sm text-muted-foreground">
                No audit records yet.
              </CardContent>
            </Card>
          )}
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="pt-6">
            <div className="flex flex-col gap-4 md:flex-row">
              <Select value={warehouseId} onValueChange={setWarehouseId}>
                <SelectTrigger className="w-full md:w-[200px]">
                  <Building2 className="mr-2 h-4 w-4" />
                  <SelectValue placeholder="Warehouse" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Warehouses</SelectItem>
                  {warehouses.map((w) => (
                    <SelectItem key={w.id} value={String(w.id)}>
                      {w.name} ({w.code})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>

              <Select value={movementType} onValueChange={setMovementType}>
                <SelectTrigger className="w-full md:w-[180px]">
                  <Filter className="mr-2 h-4 w-4" />
                  <SelectValue placeholder="Movement Type" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Types</SelectItem>
                  <SelectItem value="STOCK_IN">Stock In</SelectItem>
                  <SelectItem value="STOCK_OUT">Stock Out</SelectItem>
                  <SelectItem value="ADJUSTMENT">Adjustment</SelectItem>
                  <SelectItem value="RESERVATION">Reservation</SelectItem>
                  <SelectItem value="RELEASE">Release</SelectItem>
                  <SelectItem value="RETURN">Return</SelectItem>
                  <SelectItem value="WRITE_OFF">Write Off</SelectItem>
                  <SelectItem value="TRANSFER">Transfer</SelectItem>
                </SelectContent>
              </Select>

              <Select value={stream} onValueChange={setStream}>
                <SelectTrigger className="w-full md:w-[160px]">
                  <Package className="mr-2 h-4 w-4" />
                  <SelectValue placeholder="Stream" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Streams</SelectItem>
                  <SelectItem value="App\Domain\Product\Models\Product">Products</SelectItem>
                  <SelectItem value="App\Domain\Inventory\Models\Supply">Supplies</SelectItem>
                </SelectContent>
              </Select>

              <div className="relative w-full md:w-[260px]">
                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                <Input
                  placeholder="Search item, reason, type..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  className="pl-9"
                />
              </div>

              <Button onClick={applyFilters}>Apply Filters</Button>
            </div>
          </CardContent>
        </Card>

        {/* Audit Table */}
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">Audit Ledger</CardTitle>
            <CardDescription>
              {data.audits.total} record{data.audits.total === 1 ? '' : 's'}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-muted-foreground">
                    <th className="py-2 text-left font-medium">Date</th>
                    <th className="py-2 text-left font-medium">Item</th>
                    <th className="py-2 text-left font-medium">Warehouse</th>
                    <th className="py-2 text-left font-medium">Type</th>
                    <th className="py-2 text-right font-medium">Qty</th>
                    <th className="py-2 text-right font-medium">Before → After</th>
                    <th className="py-2 text-right font-medium">Reserved Before → After</th>
                    <th className="py-2 text-left font-medium">Reason / Notes</th>
                    <th className="py-2 text-left font-medium">User</th>
                  </tr>
                </thead>
                <tbody>
                  {data.audits.data.map((row) => (
                    <tr key={row.id} className="border-b last:border-b-0 hover:bg-muted/50">
                      <td className="py-3 whitespace-nowrap">
                        <div className="flex items-center gap-1.5">
                          <Calendar className="h-3.5 w-3.5 text-muted-foreground" />
                          {formatDate(row.created_at)}
                        </div>
                      </td>
                      <td className="py-3">
                        <div className="font-medium">{row.stockable?.name ?? 'Unknown'}</div>
                        <div className="text-xs text-muted-foreground">
                          {row.stockable?.sku ?? '—'}
                        </div>
                      </td>
                      <td className="py-3">{row.warehouse?.name ?? 'Default'}</td>
                      <td className="py-3">
                        <Badge className={movementTypeColors[row.type] ?? 'bg-muted'}>
                          {row.type.replace('_', ' ')}
                        </Badge>
                      </td>
                      <td className="py-3 text-right font-medium">
                        {row.quantity > 0 ? `+${row.quantity}` : row.quantity}
                      </td>
                      <td className="py-3 text-right">
                        {row.before_quantity} → {row.after_quantity}
                      </td>
                      <td className="py-3 text-right">
                        {row.before_reserved} → {row.after_reserved}
                      </td>
                      <td className="py-3 max-w-xs truncate" title={row.reason_notes ?? ''}>
                        {row.reason_code && (
                          <span className="text-xs text-muted-foreground mr-1">
                            [{row.reason_code}]
                          </span>
                        )}
                        {row.reason_notes ?? '—'}
                      </td>
                      <td className="py-3">
                        <div className="flex items-center gap-1.5">
                          <User className="h-3.5 w-3.5 text-muted-foreground" />
                          {row.performer?.name ?? 'System'}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {data.audits.data.length === 0 && (
                <div className="py-8 text-center text-muted-foreground">
                  No audit records match the current filters.
                </div>
              )}
            </div>

            {/* Pagination */}
            {data.audits.last_page > 1 && (
              <div className="flex items-center justify-between mt-6">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => goToPage(data.audits.current_page - 1)}
                  disabled={data.audits.current_page <= 1}
                >
                  <ArrowLeft className="mr-1.5 h-4 w-4" />
                  Previous
                </Button>
                <span className="text-sm text-muted-foreground">
                  Page {data.audits.current_page} of {data.audits.last_page}
                </span>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => goToPage(data.audits.current_page + 1)}
                  disabled={data.audits.current_page >= data.audits.last_page}
                >
                  Next
                  <ArrowRight className="ml-1.5 h-4 w-4" />
                </Button>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
