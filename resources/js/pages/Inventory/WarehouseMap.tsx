import { useState, useEffect, useCallback } from 'react';
import { toast } from 'sonner';
import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/components/ui/dialog';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { MapPin, Grid3x3, Package, TrendingUp, AlertCircle } from 'lucide-react';

interface OverviewWarehouse {
  id: number;
  name: string;
  code: string;
  is_default: boolean;
  total_locations: number;
  occupied_locations: number;
  total_stock: number;
  occupancy_pct: number;
}

interface GridCell {
  id: number;
  code: string;
  name?: string;
  type: string;
  row_index: number;
  col_index: number;
  zone_color?: string;
  capacity: number;
  is_active: boolean;
  product_stock: number;
  supply_stock: number;
  reserved_stock: number;
  total_stock: number;
  available_stock: number;
  occupancy_pct: number;
  sku_count: number;
  status: string;
}

interface MapSummary {
  total_locations: number;
  empty: number;
  low: number;
  medium: number;
  high: number;
  full: number;
  inactive: number;
  total_stock: number;
  total_reserved: number;
  total_available: number;
  total_capacity: number;
  total_skus: number;
  overall_occupancy: number;
}

interface WarehouseMapData {
  warehouse: {
    id: number;
    name: string;
    code: string;
    address?: string;
    is_active: boolean;
  };
  grid: GridCell[];
  grid_dimensions: { rows: number; cols: number };
  summary: MapSummary;
}

interface LocationDetails {
  location: {
    id: number;
    code: string;
    name?: string;
    type: string;
    capacity: number;
    is_active: boolean;
    row_index: number;
    col_index: number;
    zone_color?: string;
    warehouse: { id: number; name: string; code: string };
  };
  product_stocks: {
    id: number;
    sku?: string;
    name?: string;
    variant_sku?: string;
    variant_name?: string;
    current_stock: number;
    reserved_stock: number;
    available_stock: number;
  }[];
  supply_stocks: {
    id: number;
    sku?: string;
    name?: string;
    current_stock: number;
    reserved_stock: number;
    available_stock: number;
  }[];
}

const STATUS_COLORS: Record<string, string> = {
  EMPTY: 'bg-slate-100 dark:bg-slate-800 border-slate-300 dark:border-slate-700',
  LOW: 'bg-blue-100 dark:bg-blue-900/40 border-blue-300 dark:border-blue-700',
  MEDIUM: 'bg-yellow-100 dark:bg-yellow-900/40 border-yellow-300 dark:border-yellow-700',
  HIGH: 'bg-orange-100 dark:bg-orange-900/40 border-orange-300 dark:border-orange-700',
  FULL: 'bg-red-100 dark:bg-red-900/40 border-red-300 dark:border-red-700',
  INACTIVE: 'bg-gray-200 dark:bg-gray-800 border-gray-400 dark:border-gray-600 opacity-50',
};

const STATUS_TEXT: Record<string, string> = {
  EMPTY: 'text-slate-600 dark:text-slate-400',
  LOW: 'text-blue-700 dark:text-blue-300',
  MEDIUM: 'text-yellow-700 dark:text-yellow-300',
  HIGH: 'text-orange-700 dark:text-orange-300',
  FULL: 'text-red-700 dark:text-red-300',
  INACTIVE: 'text-gray-500 dark:text-gray-500',
};

export default function WarehouseMap({ overview }: { overview: OverviewWarehouse[] }) {
  const [selectedWarehouseId, setSelectedWarehouseId] = useState<number | null>(
    overview.length > 0 ? overview[0].id : null
  );
  const [mapData, setMapData] = useState<WarehouseMapData | null>(null);
  const [loadingMap, setLoadingMap] = useState(false);
  const [selectedCell, setSelectedCell] = useState<GridCell | null>(null);
  const [locationDetails, setLocationDetails] = useState<LocationDetails | null>(null);
  const [loadingDetails, setLoadingDetails] = useState(false);
  const [detailsOpen, setDetailsOpen] = useState(false);

  const loadMap = useCallback(async () => {
    if (!selectedWarehouseId) return;
    setLoadingMap(true);
    try {
      const res = await fetch(`/inventory/warehouse-map/api/warehouse/${selectedWarehouseId}`);
      if (!res.ok) throw new Error('Failed to load map');
      const data = await res.json();
      setMapData(data);
    } catch {
      toast.error('Failed to load warehouse map');
      setMapData(null);
    } finally {
      setLoadingMap(false);
    }
  }, [selectedWarehouseId]);

  useEffect(() => {
    loadMap();
  }, [loadMap]);

  async function loadLocationDetails(cell: GridCell) {
    setLoadingDetails(true);
    setDetailsOpen(true);
    try {
      const res = await fetch(`/inventory/warehouse-map/api/location/${cell.id}`);
      if (!res.ok) throw new Error('Failed to load details');
      const data = await res.json();
      setLocationDetails(data);
    } catch {
      toast.error('Failed to load location details');
      setLocationDetails(null);
    } finally {
      setLoadingDetails(false);
    }
  }

  function buildGridMap(cells: GridCell[], dims: { rows: number; cols: number }) {
    const map: (GridCell | null)[][] = [];
    for (let r = 0; r < dims.rows; r++) {
      const row: (GridCell | null)[] = [];
      for (let c = 0; c < dims.cols; c++) {
        const cell = cells.find((cl) => cl.row_index === r && cl.col_index === c);
        row.push(cell || null);
      }
      map.push(row);
    }
    return map;
  }

  const gridMap = mapData ? buildGridMap(mapData.grid, mapData.grid_dimensions) : [];

  return (
    <AppLayout>
      <Head title="Warehouse Map" />
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Warehouse Map</h1>
            <p className="text-sm text-muted-foreground">
              Visual layout with bin locations and occupancy
            </p>
          </div>
          {overview.length > 0 && (
            <Select
              value={String(selectedWarehouseId)}
              onValueChange={(v) => setSelectedWarehouseId(Number(v))}
            >
              <SelectTrigger className="w-[260px]">
                <SelectValue placeholder="Select warehouse" />
              </SelectTrigger>
              <SelectContent>
                {overview.map((wh) => (
                  <SelectItem key={wh.id} value={String(wh.id)}>
                    {wh.name} ({wh.code})
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </div>

        {/* Overview cards */}
        {overview.length > 0 && (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {overview.map((wh) => (
              <Card
                key={wh.id}
                className={`cursor-pointer transition-shadow hover:shadow-md ${
                  selectedWarehouseId === wh.id ? 'ring-2 ring-primary' : ''
                }`}
                onClick={() => setSelectedWarehouseId(wh.id)}
              >
                <CardContent className="p-4">
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium">{wh.name}</p>
                      <p className="text-xs text-muted-foreground">{wh.code}</p>
                    </div>
                    {wh.is_default && <Badge variant="default">Default</Badge>}
                  </div>
                  <div className="mt-3 flex items-center gap-4 text-xs">
                    <span className="flex items-center gap-1">
                      <MapPin className="h-3 w-3" />
                      {wh.occupied_locations}/{wh.total_locations} locations
                    </span>
                    <span className="flex items-center gap-1">
                      <Package className="h-3 w-3" />
                      {wh.total_stock} units
                    </span>
                  </div>
                  <div className="mt-2">
                    <div className="flex items-center justify-between text-xs">
                      <span>Occupancy</span>
                      <span className="font-medium">{wh.occupancy_pct}%</span>
                    </div>
                    <div className="mt-1 h-2 w-full rounded-full bg-muted">
                      <div
                        className="h-2 rounded-full bg-primary transition-all"
                        style={{ width: `${wh.occupancy_pct}%` }}
                      />
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

        {overview.length === 0 && (
          <Card>
            <CardContent className="flex flex-col items-center justify-center py-12">
              <AlertCircle className="h-10 w-10 text-muted-foreground" />
              <p className="mt-2 text-sm text-muted-foreground">No active warehouses found.</p>
            </CardContent>
          </Card>
        )}

        {/* Map grid */}
        {selectedWarehouseId && mapData && (
          <>
            {/* Summary stats */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-7">
              <Card>
                <CardContent className="p-3 text-center">
                  <Grid3x3 className="mx-auto h-4 w-4 text-muted-foreground" />
                  <p className="mt-1 text-lg font-bold">{mapData.summary.total_locations}</p>
                  <p className="text-xs text-muted-foreground">Locations</p>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-3 text-center">
                  <p className="text-lg font-bold text-slate-500">{mapData.summary.empty}</p>
                  <p className="text-xs text-muted-foreground">Empty</p>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-3 text-center">
                  <p className="text-lg font-bold text-blue-600">{mapData.summary.low}</p>
                  <p className="text-xs text-muted-foreground">Low</p>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-3 text-center">
                  <p className="text-lg font-bold text-yellow-600">{mapData.summary.medium}</p>
                  <p className="text-xs text-muted-foreground">Medium</p>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-3 text-center">
                  <p className="text-lg font-bold text-orange-600">{mapData.summary.high}</p>
                  <p className="text-xs text-muted-foreground">High</p>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-3 text-center">
                  <p className="text-lg font-bold text-red-600">{mapData.summary.full}</p>
                  <p className="text-xs text-muted-foreground">Full</p>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-3 text-center">
                  <TrendingUp className="mx-auto h-4 w-4 text-muted-foreground" />
                  <p className="mt-1 text-lg font-bold">{mapData.summary.overall_occupancy}%</p>
                  <p className="text-xs text-muted-foreground">Overall</p>
                </CardContent>
              </Card>
            </div>

            {/* Visual grid */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Grid3x3 className="h-5 w-5" />
                  {mapData.warehouse.name} — Layout Grid
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="overflow-x-auto">
                  <div className="inline-block">
                    {/* Column headers */}
                    <div className="flex">
                      <div className="w-10 shrink-0" />
                      {Array.from({ length: mapData.grid_dimensions.cols }, (_, c) => (
                        <div
                          key={c}
                          className="w-20 shrink-0 text-center text-xs font-medium text-muted-foreground"
                        >
                          Col {c}
                        </div>
                      ))}
                    </div>
                    {/* Grid rows */}
                    {gridMap.map((row, r) => (
                      <div key={r} className="flex">
                        <div className="flex w-10 shrink-0 items-center text-xs font-medium text-muted-foreground">
                          Row {r}
                        </div>
                        {row.map((cell, c) => (
                          <div key={c} className="w-20 shrink-0 p-1">
                            {cell ? (
                              <button
                                onClick={() => {
                                  setSelectedCell(cell);
                                  loadLocationDetails(cell);
                                }}
                                className={`flex h-16 w-full flex-col items-center justify-center rounded-md border-2 p-1 text-center transition-all hover:scale-105 hover:shadow-md ${
                                  STATUS_COLORS[cell.status] || STATUS_COLORS.EMPTY
                                }`}
                                style={
                                  cell.zone_color ? { borderColor: cell.zone_color } : undefined
                                }
                                title={`${cell.code} — ${cell.status}`}
                              >
                                <span className="text-xs font-bold">{cell.code}</span>
                                <span className={`text-[10px] ${STATUS_TEXT[cell.status] || ''}`}>
                                  {cell.total_stock}/{cell.capacity || '∞'}
                                </span>
                                <span className="text-[10px] text-muted-foreground">
                                  {cell.occupancy_pct}%
                                </span>
                              </button>
                            ) : (
                              <div className="flex h-16 w-full items-center justify-center rounded-md border border-dashed border-muted">
                                <span className="text-xs text-muted-foreground">—</span>
                              </div>
                            )}
                          </div>
                        ))}
                      </div>
                    ))}
                  </div>
                </div>

                {/* Legend */}
                <div className="mt-4 flex flex-wrap gap-3">
                  {Object.entries(STATUS_COLORS).map(([status, color]) => (
                    <div key={status} className="flex items-center gap-2">
                      <div className={`h-4 w-4 rounded border-2 ${color}`} />
                      <span className="text-xs">{status}</span>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          </>
        )}

        {loadingMap && (
          <Card>
            <CardContent className="flex items-center justify-center py-12">
              <div className="h-8 w-8 animate-spin rounded-full border-4 border-primary border-t-transparent" />
            </CardContent>
          </Card>
        )}

        {/* Location details dialog */}
        <Dialog open={detailsOpen} onOpenChange={setDetailsOpen}>
          <DialogContent className="max-w-2xl">
            <DialogHeader>
              <DialogTitle>
                {selectedCell ? `Location ${selectedCell.code}` : 'Location Details'}
              </DialogTitle>
              <DialogDescription>
                {selectedCell?.name || 'No name set'} · Type: {selectedCell?.type}
              </DialogDescription>
            </DialogHeader>

            {loadingDetails && (
              <div className="flex justify-center py-8">
                <div className="h-8 w-8 animate-spin rounded-full border-4 border-primary border-t-transparent" />
              </div>
            )}

            {locationDetails && !loadingDetails && (
              <div className="space-y-4">
                {/* Location info */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  <div className="rounded-lg border p-2 text-center">
                    <p className="text-xs text-muted-foreground">Capacity</p>
                    <p className="font-bold">{locationDetails.location.capacity || '∞'}</p>
                  </div>
                  <div className="rounded-lg border p-2 text-center">
                    <p className="text-xs text-muted-foreground">Row</p>
                    <p className="font-bold">{locationDetails.location.row_index ?? '—'}</p>
                  </div>
                  <div className="rounded-lg border p-2 text-center">
                    <p className="text-xs text-muted-foreground">Col</p>
                    <p className="font-bold">{locationDetails.location.col_index ?? '—'}</p>
                  </div>
                  <div className="rounded-lg border p-2 text-center">
                    <p className="text-xs text-muted-foreground">Active</p>
                    <p className="font-bold">{locationDetails.location.is_active ? 'Yes' : 'No'}</p>
                  </div>
                </div>

                {/* Product stocks */}
                {locationDetails.product_stocks.length > 0 && (
                  <div>
                    <h4 className="mb-2 text-sm font-medium">Product Stocks</h4>
                    <div className="max-h-40 overflow-y-auto rounded-md border">
                      <Table>
                        <TableHeader>
                          <TableRow>
                            <TableHead className="h-8 text-xs">SKU</TableHead>
                            <TableHead className="h-8 text-xs">Name</TableHead>
                            <TableHead className="h-8 text-xs text-right">Stock</TableHead>
                            <TableHead className="h-8 text-xs text-right">Reserved</TableHead>
                            <TableHead className="h-8 text-xs text-right">Available</TableHead>
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {locationDetails.product_stocks.map((s) => (
                            <TableRow key={s.id}>
                              <TableCell className="py-1 text-xs">{s.sku}</TableCell>
                              <TableCell className="py-1 text-xs">
                                {s.name}
                                {s.variant_name ? ` (${s.variant_name})` : ''}
                              </TableCell>
                              <TableCell className="py-1 text-right text-xs font-medium">
                                {s.current_stock}
                              </TableCell>
                              <TableCell className="py-1 text-right text-xs">
                                {s.reserved_stock}
                              </TableCell>
                              <TableCell className="py-1 text-right text-xs font-medium">
                                {s.available_stock}
                              </TableCell>
                            </TableRow>
                          ))}
                        </TableBody>
                      </Table>
                    </div>
                  </div>
                )}

                {/* Supply stocks */}
                {locationDetails.supply_stocks.length > 0 && (
                  <div>
                    <h4 className="mb-2 text-sm font-medium">Supply Stocks</h4>
                    <div className="max-h-40 overflow-y-auto rounded-md border">
                      <Table>
                        <TableHeader>
                          <TableRow>
                            <TableHead className="h-8 text-xs">SKU</TableHead>
                            <TableHead className="h-8 text-xs">Name</TableHead>
                            <TableHead className="h-8 text-xs text-right">Stock</TableHead>
                            <TableHead className="h-8 text-xs text-right">Reserved</TableHead>
                            <TableHead className="h-8 text-xs text-right">Available</TableHead>
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {locationDetails.supply_stocks.map((s) => (
                            <TableRow key={s.id}>
                              <TableCell className="py-1 text-xs">{s.sku}</TableCell>
                              <TableCell className="py-1 text-xs">{s.name}</TableCell>
                              <TableCell className="py-1 text-right text-xs font-medium">
                                {s.current_stock}
                              </TableCell>
                              <TableCell className="py-1 text-right text-xs">
                                {s.reserved_stock}
                              </TableCell>
                              <TableCell className="py-1 text-right text-xs font-medium">
                                {s.available_stock}
                              </TableCell>
                            </TableRow>
                          ))}
                        </TableBody>
                      </Table>
                    </div>
                  </div>
                )}

                {locationDetails.product_stocks.length === 0 &&
                  locationDetails.supply_stocks.length === 0 && (
                    <p className="py-4 text-center text-sm text-muted-foreground">
                      No stock items in this location.
                    </p>
                  )}
              </div>
            )}
          </DialogContent>
        </Dialog>
      </div>
    </AppLayout>
  );
}
