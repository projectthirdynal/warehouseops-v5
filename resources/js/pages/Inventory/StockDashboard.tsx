import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  AlertTriangle,
  ArrowRight,
  Box,
  Building2,
  Filter,
  Package,
  PackageX,
  RefreshCw,
  ShoppingCart,
  TrendingDown,
  Truck,
  Warehouse,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { formatCurrency, cn } from '@/lib/utils';

interface Movement {
  id: number;
  stream: 'product' | 'supply';
  type: string;
  quantity: number;
  item_name: string;
  item_sku: string;
  warehouse_name: string;
  created_at: string;
}

interface Alert {
  id: number;
  alert_type: string;
  status: string;
  warehouse: string;
  item_name: string;
  item_sku: string;
  current_stock: number;
  reserved_stock: number;
  reorder_point: number;
  suggested_reorder_qty: number;
  created_at: string;
}

interface WarehouseBreakdown {
  id: number;
  name: string;
  code: string;
  stock_value: number;
  low_stock_count: number;
  product_value: number;
  supply_value: number;
}

interface ReorderTrigger {
  stream: 'product' | 'supply';
  item_id: number;
  item_name: string;
  item_sku: string;
  warehouse: string;
  available: number;
  reorder_point: number;
  suggested_reorder_qty: number;
}

interface TopMover {
  id: number;
  sku: string;
  name: string;
  total_qty: number;
}

interface Summary {
  total_sku_count: number;
  product_stock_value: number;
  supply_stock_value: number;
  total_stock_value: number;
  low_stock_count: number;
  out_of_stock_count: number;
  open_alert_count: number;
  warehouse_id: number | null;
}

interface DashboardData {
  summary: Summary;
  warehouse_breakdown: WarehouseBreakdown[];
  low_stock_alerts: Alert[];
  recent_movements: Movement[];
  top_movers: TopMover[];
  reorder_triggers: ReorderTrigger[];
  filters: {
    warehouse_id?: string;
    alert_type?: string;
  };
}

interface Props {
  data: DashboardData;
  warehouses: { id: number; name: string; code: string }[];
  filters: Record<string, string | undefined>;
}

const alertStyles: Record<string, string> = {
  LOW_STOCK: 'bg-warning text-white',
  OUT_OF_STOCK: 'bg-destructive text-white',
  OVERSTOCK: 'bg-info text-white',
};

const statusStyles: Record<string, string> = {
  OPEN: 'bg-destructive text-white',
  ACKNOWLEDGED: 'bg-warning text-white',
  RESOLVED: 'bg-success text-white',
};

export default function StockDashboard({ data, warehouses, filters }: Props) {
  const [warehouseId, setWarehouseId] = useState(filters?.warehouse_id ?? 'all');
  const [alertType, setAlertType] = useState(filters?.alert_type ?? 'all');
  const [syncing, setSyncing] = useState(false);

  const applyFilters = () => {
    router.get(
      '/inventory/stock/dashboard',
      {
        warehouse_id: warehouseId !== 'all' ? warehouseId : undefined,
        alert_type: alertType !== 'all' ? alertType : undefined,
      },
      { preserveState: true }
    );
  };

  const handleSync = async () => {
    setSyncing(true);
    try {
      const token =
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
      await fetch('/inventory/stock/alerts/sync', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': token },
      });
      router.reload();
    } catch {
      // ignore
    } finally {
      setSyncing(false);
    }
  };

  const acknowledge = async (alertId: number) => {
    try {
      const token =
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
      await fetch(`/inventory/stock/alerts/${alertId}/acknowledge`, {
        method: 'PATCH',
        headers: { 'X-CSRF-TOKEN': token },
      });
      router.reload();
    } catch {
      // ignore
    }
  };

  const summaryCards = [
    {
      title: 'Total Stock Value',
      value: formatCurrency(data.summary.total_stock_value),
      icon: ShoppingCart,
      tone: 'text-primary',
    },
    {
      title: 'Total SKUs',
      value: data.summary.total_sku_count,
      icon: Box,
      tone: 'text-muted-foreground',
    },
    {
      title: 'Low Stock',
      value: data.summary.low_stock_count,
      icon: TrendingDown,
      tone: 'text-warning',
    },
    {
      title: 'Out of Stock',
      value: data.summary.out_of_stock_count,
      icon: PackageX,
      tone: 'text-destructive',
    },
    {
      title: 'Open Alerts',
      value: data.summary.open_alert_count,
      icon: AlertTriangle,
      tone: 'text-destructive',
    },
  ];

  return (
    <AppLayout>
      <Head title="Real-Time Stock Dashboard" />
      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-xl font-bold font-display tracking-tight flex items-center gap-2">
              <Warehouse className="h-5 w-5 text-primary" />
              Real-Time Stock Dashboard
            </h1>
            <p className="text-muted-foreground">
              Live stock levels, low-stock alerts, and reorder triggers
            </p>
          </div>
          <Button variant="outline" size="sm" onClick={handleSync} disabled={syncing}>
            <RefreshCw className={`mr-1.5 h-4 w-4 ${syncing ? 'animate-spin' : ''}`} />
            Sync Alerts
          </Button>
        </div>

        {/* Summary Cards */}
        <div className="grid gap-4 md:grid-cols-5">
          {summaryCards.map((card) => (
            <Card key={card.title}>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  {card.title}
                </CardTitle>
                <card.icon className={`h-4 w-4 ${card.tone}`} />
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold font-display">{card.value}</div>
              </CardContent>
            </Card>
          ))}
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="pt-6">
            <div className="flex flex-col gap-4 md:flex-row">
              <Select value={warehouseId} onValueChange={setWarehouseId}>
                <SelectTrigger className="w-full md:w-[220px]">
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
              <Select value={alertType} onValueChange={setAlertType}>
                <SelectTrigger className="w-full md:w-[180px]">
                  <Filter className="mr-2 h-4 w-4" />
                  <SelectValue placeholder="Alert Type" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Alerts</SelectItem>
                  <SelectItem value="LOW_STOCK">Low Stock</SelectItem>
                  <SelectItem value="OUT_OF_STOCK">Out of Stock</SelectItem>
                  <SelectItem value="OVERSTOCK">Overstock</SelectItem>
                </SelectContent>
              </Select>
              <Button onClick={applyFilters}>Apply Filters</Button>
            </div>
          </CardContent>
        </Card>

        <div className="grid gap-6 lg:grid-cols-3">
          {/* Low Stock Alerts */}
          <Card className="lg:col-span-2">
            <CardHeader>
              <CardTitle className="text-sm font-medium flex items-center gap-2">
                <AlertTriangle className="h-4 w-4 text-warning" />
                Active Stock Alerts
              </CardTitle>
              <CardDescription>
                {data.low_stock_alerts.length} alert{data.low_stock_alerts.length === 1 ? '' : 's'}
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-muted-foreground">
                      <th className="py-2 text-left font-medium">Item</th>
                      <th className="py-2 text-left font-medium">Warehouse</th>
                      <th className="py-2 text-right font-medium">Available</th>
                      <th className="py-2 text-right font-medium">Reorder Point</th>
                      <th className="py-2 text-center font-medium">Status</th>
                      <th className="py-2 text-right font-medium">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.low_stock_alerts.map((alert) => (
                      <tr key={alert.id} className="border-b last:border-b-0 hover:bg-muted/50">
                        <td className="py-3">
                          <div className="font-medium">{alert.item_name}</div>
                          <div className="text-xs text-muted-foreground">{alert.item_sku}</div>
                        </td>
                        <td className="py-3">{alert.warehouse}</td>
                        <td className="py-3 text-right">
                          {alert.current_stock - alert.reserved_stock}
                        </td>
                        <td className="py-3 text-right">{alert.reorder_point}</td>
                        <td className="py-3 text-center">
                          <div className="flex flex-col items-center gap-1">
                            <Badge className={cn(alertStyles[alert.alert_type] ?? 'bg-muted')}>
                              {alert.alert_type.replace('_', ' ')}
                            </Badge>
                            <Badge
                              variant="outline"
                              className={cn(statusStyles[alert.status] ?? '')}
                            >
                              {alert.status}
                            </Badge>
                          </div>
                        </td>
                        <td className="py-3 text-right">
                          {alert.status === 'OPEN' && (
                            <Button size="sm" variant="ghost" onClick={() => acknowledge(alert.id)}>
                              Ack
                            </Button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {data.low_stock_alerts.length === 0 && (
                  <div className="py-8 text-center text-muted-foreground">No active alerts.</div>
                )}
              </div>
            </CardContent>
          </Card>

          {/* Warehouse Breakdown */}
          <Card className="lg:col-span-1">
            <CardHeader>
              <CardTitle className="text-sm font-medium flex items-center gap-2">
                <Building2 className="h-4 w-4 text-primary" />
                Warehouse Breakdown
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                {data.warehouse_breakdown.map((w) => (
                  <div key={w.id} className="rounded-lg border p-3">
                    <div className="flex items-center justify-between">
                      <span className="font-medium">{w.name}</span>
                      <span className="text-sm font-bold">{formatCurrency(w.stock_value)}</span>
                    </div>
                    <div className="mt-2 flex items-center justify-between text-xs text-muted-foreground">
                      <span>Products: {formatCurrency(w.product_value)}</span>
                      <span>Supplies: {formatCurrency(w.supply_value)}</span>
                    </div>
                    {w.low_stock_count > 0 && (
                      <Badge variant="outline" className="mt-2 text-warning">
                        <AlertTriangle className="mr-1 h-3 w-3" />
                        {w.low_stock_count} low stock
                      </Badge>
                    )}
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </div>

        <div className="grid gap-6 lg:grid-cols-3">
          {/* Reorder Triggers */}
          <Card className="lg:col-span-2">
            <CardHeader>
              <CardTitle className="text-sm font-medium flex items-center gap-2">
                <Truck className="h-4 w-4 text-primary" />
                Reorder Triggers
              </CardTitle>
              <CardDescription>
                Items at or below reorder point with suggested order quantities
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-muted-foreground">
                      <th className="py-2 text-left font-medium">Item</th>
                      <th className="py-2 text-left font-medium">Warehouse</th>
                      <th className="py-2 text-right font-medium">Available</th>
                      <th className="py-2 text-right font-medium">Reorder Point</th>
                      <th className="py-2 text-right font-medium">Suggested Qty</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.reorder_triggers.map((item, idx) => (
                      <tr key={idx} className="border-b last:border-b-0 hover:bg-muted/50">
                        <td className="py-3">
                          <div className="flex items-center gap-2">
                            {item.stream === 'product' ? (
                              <Package className="h-4 w-4 text-primary" />
                            ) : (
                              <Box className="h-4 w-4 text-muted-foreground" />
                            )}
                            <div>
                              <div className="font-medium">{item.item_name}</div>
                              <div className="text-xs text-muted-foreground">{item.item_sku}</div>
                            </div>
                          </div>
                        </td>
                        <td className="py-3">{item.warehouse}</td>
                        <td className="py-3 text-right">{item.available}</td>
                        <td className="py-3 text-right">{item.reorder_point}</td>
                        <td className="py-3 text-right font-bold">{item.suggested_reorder_qty}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {data.reorder_triggers.length === 0 && (
                  <div className="py-8 text-center text-muted-foreground">
                    No reorder triggers at this time.
                  </div>
                )}
              </div>
            </CardContent>
          </Card>

          {/* Top Movers */}
          <Card className="lg:col-span-1">
            <CardHeader>
              <CardTitle className="text-sm font-medium flex items-center gap-2">
                <TrendingDown className="h-4 w-4 text-primary" />
                Top Movers (30d)
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-3">
                {data.top_movers.map((mover) => (
                  <div key={mover.id} className="flex items-center justify-between text-sm">
                    <div>
                      <div className="font-medium">{mover.name}</div>
                      <div className="text-xs text-muted-foreground">{mover.sku}</div>
                    </div>
                    <Badge variant="outline">{mover.total_qty} units</Badge>
                  </div>
                ))}
                {data.top_movers.length === 0 && (
                  <div className="py-4 text-center text-muted-foreground">No movement data.</div>
                )}
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Recent Movements */}
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium flex items-center gap-2">
              <ArrowRight className="h-4 w-4 text-primary" />
              Recent Movements
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-muted-foreground">
                    <th className="py-2 text-left font-medium">Time</th>
                    <th className="py-2 text-left font-medium">Item</th>
                    <th className="py-2 text-left font-medium">Warehouse</th>
                    <th className="py-2 text-left font-medium">Type</th>
                    <th className="py-2 text-right font-medium">Quantity</th>
                  </tr>
                </thead>
                <tbody>
                  {data.recent_movements.map((movement) => (
                    <tr key={movement.id} className="border-b last:border-b-0 hover:bg-muted/50">
                      <td className="py-3">
                        {new Date(movement.created_at).toLocaleString('en-PH')}
                      </td>
                      <td className="py-3">
                        <div className="font-medium">{movement.item_name}</div>
                        <div className="text-xs text-muted-foreground">{movement.item_sku}</div>
                      </td>
                      <td className="py-3">{movement.warehouse_name}</td>
                      <td className="py-3">
                        <Badge variant="outline">{movement.type}</Badge>
                      </td>
                      <td
                        className={cn(
                          'py-3 text-right font-medium',
                          movement.quantity > 0 ? 'text-success' : 'text-destructive'
                        )}
                      >
                        {movement.quantity > 0 ? '+' : ''}
                        {movement.quantity}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {data.recent_movements.length === 0 && (
                <div className="py-8 text-center text-muted-foreground">No recent movements.</div>
              )}
            </div>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
