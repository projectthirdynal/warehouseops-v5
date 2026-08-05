import { useState, useEffect, useCallback } from 'react';
import { toast } from 'sonner';
import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/components/ui/dialog';
import {
  AreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Legend,
} from 'recharts';
import { TrendingUp, TrendingDown, Minus, AlertTriangle, LineChart } from 'lucide-react';

interface SummaryRow {
  product_id: number;
  sku: string;
  name: string;
  total_historical_qty: number;
  avg_daily_qty: number;
  growth_rate: number;
  trend_direction: 'increasing' | 'decreasing' | 'stable';
  forecast_30d_qty: number;
  current_stock: number;
  available_stock: number;
  reorder_point: number;
  suggested_reorder_qty: number;
  needs_reorder: boolean;
}

interface HistoryPoint {
  date: string;
  qty: number;
}

interface ForecastPoint {
  date: string;
  day: string;
  predicted_qty: number;
  confidence: number;
}

interface ProductForecastDetail {
  product: { id: number; sku: string; name: string };
  history: HistoryPoint[];
  summary: {
    total_historical_qty: number;
    avg_daily_qty: number;
    growth_rate: number;
    trend_direction: string;
    data_sufficient: boolean;
    history_days: number;
    sale_day_count: number;
  };
  forecast: ForecastPoint[];
  total_forecast_qty: number;
  stock: {
    current_stock: number;
    reserved_stock: number;
    available_stock: number;
    reorder_point: number;
    suggested_reorder_qty: number;
    needs_reorder: boolean;
  };
}

function TrendBadge({ direction, rate }: { direction: string; rate: number }) {
  if (direction === 'increasing') {
    return (
      <Badge className="gap-1 bg-green-100 text-green-800 hover:bg-green-100 dark:bg-green-900/40 dark:text-green-300">
        <TrendingUp className="h-3 w-3" />
        {rate > 0 ? '+' : ''}
        {rate}%
      </Badge>
    );
  }
  if (direction === 'decreasing') {
    return (
      <Badge className="gap-1 bg-red-100 text-red-800 hover:bg-red-100 dark:bg-red-900/40 dark:text-red-300">
        <TrendingDown className="h-3 w-3" />
        {rate}%
      </Badge>
    );
  }
  return (
    <Badge variant="secondary" className="gap-1">
      <Minus className="h-3 w-3" />
      {rate}%
    </Badge>
  );
}

export default function DemandForecasting({ summary }: { summary: SummaryRow[] }) {
  const [selectedProductId, setSelectedProductId] = useState<number | null>(null);
  const [detail, setDetail] = useState<ProductForecastDetail | null>(null);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [detailOpen, setDetailOpen] = useState(false);

  const loadDetail = useCallback(async (productId: number) => {
    setLoadingDetail(true);
    setDetailOpen(true);
    try {
      const res = await fetch(`/inventory/demand-forecast/api/product/${productId}`);
      if (!res.ok) throw new Error('Failed to load forecast');
      const data = await res.json();
      setDetail(data);
    } catch {
      toast.error('Failed to load product forecast');
      setDetail(null);
    } finally {
      setLoadingDetail(false);
    }
  }, []);

  useEffect(() => {
    if (selectedProductId) {
      loadDetail(selectedProductId);
    }
  }, [selectedProductId, loadDetail]);

  const chartData = detail
    ? [
        ...detail.history.slice(-30).map((h) => ({
          date: h.date,
          historical: h.qty,
          forecast: null as number | null,
        })),
        ...detail.forecast.map((f) => ({
          date: f.date,
          historical: null as number | null,
          forecast: f.predicted_qty,
        })),
      ]
    : [];

  const reorderCount = summary.filter((s) => s.needs_reorder).length;

  return (
    <AppLayout>
      <Head title="Demand Forecasting" />
      <div className="space-y-6 p-6">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Demand Forecasting</h1>
          <p className="text-sm text-muted-foreground">
            Forecasted demand from historical usage and seasonality
          </p>
        </div>

        {/* Summary stats */}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-2">
                <LineChart className="h-4 w-4 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Products Tracked</p>
              </div>
              <p className="mt-1 text-2xl font-bold">{summary.length}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-2">
                <TrendingUp className="h-4 w-4 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Increasing Demand</p>
              </div>
              <p className="mt-1 text-2xl font-bold">
                {summary.filter((s) => s.trend_direction === 'increasing').length}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-2">
                <AlertTriangle className="h-4 w-4 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Need Reorder</p>
              </div>
              <p className="mt-1 text-2xl font-bold">{reorderCount}</p>
            </CardContent>
          </Card>
        </div>

        {/* Product table */}
        <Card>
          <CardHeader>
            <CardTitle>Product Demand Overview</CardTitle>
          </CardHeader>
          <CardContent>
            {summary.length === 0 ? (
              <p className="py-8 text-center text-sm text-muted-foreground">
                No sales history available to forecast demand.
              </p>
            ) : (
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>SKU</TableHead>
                      <TableHead>Product</TableHead>
                      <TableHead className="text-right">Total Sold</TableHead>
                      <TableHead className="text-right">Avg/Day</TableHead>
                      <TableHead>Trend</TableHead>
                      <TableHead className="text-right">Forecast (30d)</TableHead>
                      <TableHead className="text-right">Available Stock</TableHead>
                      <TableHead className="text-right">Suggested Reorder</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {summary.map((row) => (
                      <TableRow
                        key={row.product_id}
                        className="cursor-pointer hover:bg-muted/50"
                        onClick={() => setSelectedProductId(row.product_id)}
                      >
                        <TableCell className="font-mono text-xs">{row.sku}</TableCell>
                        <TableCell>{row.name}</TableCell>
                        <TableCell className="text-right">{row.total_historical_qty}</TableCell>
                        <TableCell className="text-right">{row.avg_daily_qty}</TableCell>
                        <TableCell>
                          <TrendBadge direction={row.trend_direction} rate={row.growth_rate} />
                        </TableCell>
                        <TableCell className="text-right font-medium">
                          {row.forecast_30d_qty}
                        </TableCell>
                        <TableCell className="text-right">{row.available_stock}</TableCell>
                        <TableCell className="text-right">
                          {row.needs_reorder ? (
                            <Badge variant="destructive">{row.suggested_reorder_qty}</Badge>
                          ) : (
                            <span className="text-muted-foreground">0</span>
                          )}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Product detail dialog */}
        <Dialog open={detailOpen} onOpenChange={setDetailOpen}>
          <DialogContent className="max-w-3xl">
            <DialogHeader>
              <DialogTitle>{detail?.product.name || 'Product Forecast'}</DialogTitle>
              <DialogDescription>
                {detail?.product.sku} — historical usage and 30-day forecast
              </DialogDescription>
            </DialogHeader>

            {loadingDetail && (
              <div className="flex justify-center py-8">
                <div className="h-8 w-8 animate-spin rounded-full border-4 border-primary border-t-transparent" />
              </div>
            )}

            {detail && !loadingDetail && (
              <div className="space-y-4">
                {!detail.summary.data_sufficient && (
                  <div className="flex items-center gap-2 rounded-md border border-yellow-300 bg-yellow-50 p-3 text-sm text-yellow-800 dark:border-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300">
                    <AlertTriangle className="h-4 w-4 shrink-0" />
                    Insufficient sales history for a reliable trend — showing a simple average-based
                    forecast.
                  </div>
                )}

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  <div className="rounded-lg border p-2 text-center">
                    <p className="text-xs text-muted-foreground">Avg/Day</p>
                    <p className="font-bold">{detail.summary.avg_daily_qty}</p>
                  </div>
                  <div className="rounded-lg border p-2 text-center">
                    <p className="text-xs text-muted-foreground">Forecast (30d)</p>
                    <p className="font-bold">{detail.total_forecast_qty}</p>
                  </div>
                  <div className="rounded-lg border p-2 text-center">
                    <p className="text-xs text-muted-foreground">Available Stock</p>
                    <p className="font-bold">{detail.stock.available_stock}</p>
                  </div>
                  <div className="rounded-lg border p-2 text-center">
                    <p className="text-xs text-muted-foreground">Suggested Reorder</p>
                    <p className={`font-bold ${detail.stock.needs_reorder ? 'text-red-600' : ''}`}>
                      {detail.stock.suggested_reorder_qty}
                    </p>
                  </div>
                </div>

                <div className="h-64 w-full">
                  <ResponsiveContainer width="100%" height="100%">
                    <AreaChart data={chartData}>
                      <CartesianGrid strokeDasharray="3 3" opacity={0.3} />
                      <XAxis dataKey="date" tick={{ fontSize: 10 }} minTickGap={20} />
                      <YAxis tick={{ fontSize: 10 }} />
                      <Tooltip />
                      <Legend />
                      <Area
                        type="monotone"
                        dataKey="historical"
                        name="Historical"
                        stroke="#6366f1"
                        fill="#6366f1"
                        fillOpacity={0.2}
                        connectNulls
                      />
                      <Area
                        type="monotone"
                        dataKey="forecast"
                        name="Forecast"
                        stroke="#f59e0b"
                        fill="#f59e0b"
                        fillOpacity={0.2}
                        strokeDasharray="4 4"
                        connectNulls
                      />
                    </AreaChart>
                  </ResponsiveContainer>
                </div>
              </div>
            )}
          </DialogContent>
        </Dialog>
      </div>
    </AppLayout>
  );
}
