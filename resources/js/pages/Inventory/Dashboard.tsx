import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import {
  Package, Warehouse, AlertTriangle, ShoppingCart, FileText, TrendingUp, Box,
} from 'lucide-react';
import { formatDate } from '@/lib/utils';

interface MovementRow {
  id: number;
  product?: { id: number; sku: string; name: string };
  type: string;
  quantity: number;
  notes?: string;
  created_at: string;
}

interface LowStockRow {
  id: number;
  product?: { id: number; sku: string; name: string };
  warehouse?: { id: number; name: string };
  current_stock: number;
  reserved_stock: number;
  available_stock: number;
  reorder_point: number;
}

interface Props {
  stats: {
    total_products: number;
    total_supplies: number;
    total_warehouses: number;
    stock_value: number;
    low_stock_count: number;
    pending_prs: number;
    open_pos: number;
  };
  recent_movements: MovementRow[];
  low_stock: LowStockRow[];
}

export default function InventoryDashboard({ stats, recent_movements, low_stock }: Props) {
  return (
    <AppLayout>
      <Head title="Inventory Dashboard" />

      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">Inventory Dashboard</h1>
            <p className="text-sm text-muted-foreground">
              Real-time stock visibility across warehouses, with procurement health.
            </p>
          </div>
          <div className="flex gap-2">
            <Link href="/procurement/requests/create">
              <Button variant="outline"><FileText className="mr-2 h-4 w-4" />New PR</Button>
            </Link>
            <Link href="/procurement/orders/create">
              <Button><ShoppingCart className="mr-2 h-4 w-4" />New PO</Button>
            </Link>
          </div>
        </div>

        {/* Top KPIs */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <KpiCard icon={<Package className="h-4 w-4 text-blue-500" />} label="Active Products" value={stats.total_products} />
          <KpiCard icon={<Box className="h-4 w-4 text-purple-500" />}    label="Active Supplies" value={stats.total_supplies} />
          <KpiCard icon={<Warehouse className="h-4 w-4 text-emerald-500" />} label="Warehouses" value={stats.total_warehouses} />
          <KpiCard
            icon={<TrendingUp className="h-4 w-4 text-green-600" />}
            label="Stock Value (cost)"
            value={`₱${Number(stats.stock_value).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`}
          />
        </div>

        {/* Action items */}
        <div className="grid gap-4 sm:grid-cols-3">
          <ActionCard
            href="/inventory/movements"
            tone="orange"
            icon={<AlertTriangle className="h-5 w-5 text-orange-500" />}
            label="Low Stock"
            value={stats.low_stock_count}
            sub="below reorder point"
          />
          <ActionCard
            href="/procurement/requests?status=SUBMITTED"
            tone="blue"
            icon={<FileText className="h-5 w-5 text-blue-500" />}
            label="Pending Approvals"
            value={stats.pending_prs}
            sub="purchase requests"
          />
          <ActionCard
            href="/procurement/orders?status=SENT"
            tone="green"
            icon={<ShoppingCart className="h-5 w-5 text-green-600" />}
            label="Open POs"
            value={stats.open_pos}
            sub="awaiting receipt"
          />
        </div>

        {/* Two-column: low stock + recent movements */}
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-base">Low Stock</CardTitle>
              <Link href="/inventory/movements" className="text-xs text-blue-600 hover:underline">View all</Link>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Product</TableHead>
                    <TableHead>Warehouse</TableHead>
                    <TableHead className="text-right">Available</TableHead>
                    <TableHead className="text-right">Reorder pt</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {low_stock.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={4} className="py-8 text-center text-sm text-muted-foreground">All stock above reorder point.</TableCell>
                    </TableRow>
                  ) : low_stock.map(s => (
                    <TableRow key={s.id}>
                      <TableCell>
                        <Link href={`/products/${s.product?.id}`} className="font-mono text-sm hover:underline">
                          {s.product?.sku} <span className="font-sans text-muted-foreground">— {s.product?.name}</span>
                        </Link>
                      </TableCell>
                      <TableCell className="text-sm">{s.warehouse?.name ?? '—'}</TableCell>
                      <TableCell className="text-right font-medium text-orange-700">{s.available_stock ?? (s.current_stock - s.reserved_stock)}</TableCell>
                      <TableCell className="text-right text-sm text-muted-foreground">{s.reorder_point}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-base">Recent Movements</CardTitle>
              <Link href="/inventory/movements" className="text-xs text-blue-600 hover:underline">View all</Link>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Time</TableHead>
                    <TableHead>Product</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead className="text-right">Qty</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {recent_movements.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={4} className="py-8 text-center text-sm text-muted-foreground">No movements yet.</TableCell>
                    </TableRow>
                  ) : recent_movements.map(m => (
                    <TableRow key={m.id}>
                      <TableCell className="text-xs text-muted-foreground whitespace-nowrap">{formatDate(m.created_at)}</TableCell>
                      <TableCell className="text-sm">{m.product?.name ?? '—'}</TableCell>
                      <TableCell>
                        <span className={`rounded px-2 py-0.5 text-xs font-medium ${typeStyle(m.type)}`}>{m.type}</span>
                      </TableCell>
                      <TableCell className={`text-right font-medium ${m.quantity < 0 ? 'text-red-600' : 'text-green-600'}`}>
                        {m.quantity > 0 ? `+${m.quantity}` : m.quantity}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}

function KpiCard({ icon, label, value }: { icon: React.ReactNode; label: string; value: string | number }) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="flex items-center gap-2">
          {icon}
          <span className="text-2xl font-bold">{value}</span>
        </div>
      </CardContent>
    </Card>
  );
}

function ActionCard({ href, icon, label, value, sub, tone }: {
  href: string; icon: React.ReactNode; label: string; value: number; sub: string;
  tone: 'orange' | 'blue' | 'green';
}) {
  const toneCls = { orange: 'border-orange-200 bg-orange-50', blue: 'border-blue-200 bg-blue-50', green: 'border-green-200 bg-green-50' }[tone];
  return (
    <Link href={href}>
      <Card className={`cursor-pointer transition-shadow hover:shadow-md ${toneCls}`}>
        <CardContent className="flex items-center justify-between p-4">
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
            <p className="mt-1 text-3xl font-bold">{value}</p>
            <p className="text-xs text-muted-foreground">{sub}</p>
          </div>
          {icon}
        </CardContent>
      </Card>
    </Link>
  );
}

function typeStyle(type: string): string {
  switch (type) {
    case 'STOCK_IN':    return 'bg-green-100 text-green-700';
    case 'STOCK_OUT':   return 'bg-red-100 text-red-700';
    case 'ADJUSTMENT':  return 'bg-yellow-100 text-yellow-700';
    case 'RETURN':      return 'bg-blue-100 text-blue-700';
    case 'RESERVATION': return 'bg-purple-100 text-purple-700';
    case 'RELEASE':     return 'bg-indigo-100 text-indigo-700';
    default:            return 'bg-gray-100 text-gray-700';
  }
}
