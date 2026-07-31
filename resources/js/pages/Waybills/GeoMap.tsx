import { useEffect, useState, useRef } from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { MapPin, Package, Truck, Navigation, Loader2, ChevronRight, X, Clock } from 'lucide-react';
import axios from 'axios';
import { formatDate } from '@/lib/utils';

declare global {
  interface Window {
    L: any;
  }
}

interface Marker {
  id: number;
  waybill_number: string;
  status: string;
  status_label: string;
  courier: string;
  receiver_name: string;
  receiver_phone: string;
  city: string;
  address: string;
  cod_amount: number;
  item_name: string;
  item_qty: number;
  lat: number;
  lng: number;
  location_description: string | null;
  last_location_at: string | null;
  dispatched_at: string | null;
  tracking_history: { status: string; location: string | null; tracked_at: string | null }[];
}

interface Summary {
  total_in_transit: number;
  total_mapped: number;
  total_unmapped: number;
  status_counts: Record<string, number>;
  courier_counts: Record<string, number>;
  top_cities: Record<string, number>;
}

interface Stats {
  total_in_transit: number;
  with_coordinates: number;
  with_location_text: number;
  coverage_percent: number;
  by_courier: Record<string, number>;
  by_status: Record<string, number>;
  recent_updates: {
    id: number;
    waybill_number: string;
    courier: string;
    status: string;
    location: string | null;
    last_location_at: string | null;
    city: string | null;
  }[];
}

interface Props {
  markers: Marker[];
  summary: Summary;
  stats: Stats;
  filters: { courier?: string; status?: string };
}

const STATUS_COLORS: Record<string, string> = {
  DISPATCHED: '#3b82f6',
  PICKED_UP: '#3b82f6',
  IN_TRANSIT: '#06b6d4',
  ARRIVED_HUB: '#0891b2',
  OUT_FOR_DELIVERY: '#f59e0b',
  DELIVERY_FAILED: '#ef4444',
  RETURNING: '#f97316',
};

function formatPeso(amount: number): string {
  return (
    '₱' +
    Number(amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  );
}

export default function GeoMap({ markers, summary, stats, filters }: Props) {
  const [courierFilter, setCourierFilter] = useState(filters.courier ?? 'all');
  const [statusFilter, setStatusFilter] = useState(filters.status ?? 'all');
  const [liveData, setLiveData] = useState({ markers, summary });
  const [loading, setLoading] = useState(false);
  const [historyData, setHistoryData] = useState<any>(null);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [showHistory, setShowHistory] = useState(false);

  const mapRef = useRef<any>(null);
  const mapInstanceRef = useRef<any>(null);
  const markersLayerRef = useRef<any>(null);

  function loadLeaflet(): Promise<void> {
    return new Promise((resolve) => {
      if (window.L) {
        resolve();
        return;
      }
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
      document.head.appendChild(link);

      const script = document.createElement('script');
      script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
      script.onload = () => resolve();
      document.head.appendChild(script);
    });
  }

  function initMap() {
    if (mapInstanceRef.current || !mapRef.current || !window.L) return;

    const L = window.L;
    mapInstanceRef.current = L.map(mapRef.current, {
      center: [12.8797, 121.774],
      zoom: 6,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors',
      maxZoom: 19,
    }).addTo(mapInstanceRef.current);

    markersLayerRef.current = L.layerGroup().addTo(mapInstanceRef.current);
    renderMarkers(liveData.markers);
  }

  function renderMarkers(mks: Marker[]) {
    if (!mapInstanceRef.current || !window.L) return;
    const L = window.L;
    markersLayerRef.current.clearLayers();

    mks.forEach((m) => {
      const color = STATUS_COLORS[m.status] ?? '#6b7280';
      const icon = L.divIcon({
        className: 'custom-marker',
        html: `<div style="background:${color};width:14px;height:14px;border-radius:50%;border:2px solid white;box-shadow:0 1px 3px rgba(0,0,0,0.4);"></div>`,
        iconSize: [14, 14],
        iconAnchor: [7, 7],
      });

      const marker = L.marker([m.lat, m.lng], { icon });
      marker.bindPopup(`
        <div style="min-width:200px;font-size:13px;">
          <div style="font-weight:600;margin-bottom:4px;">${m.waybill_number}</div>
          <div style="color:${color};font-weight:500;margin-bottom:4px;">${m.status_label}</div>
          <div style="color:#666;margin-bottom:2px;">${m.courier} · ${m.city || 'N/A'}</div>
          <div style="color:#666;margin-bottom:2px;">${m.receiver_name}</div>
          <div style="color:#888;font-size:11px;">COD: ${formatPeso(m.cod_amount)}</div>
          ${m.location_description ? `<div style="color:#888;font-size:11px;margin-top:2px;">📍 ${m.location_description}</div>` : ''}
        </div>
      `);
      markersLayerRef.current.addLayer(marker);
    });

    if (mks.length > 0) {
      const bounds = L.latLngBounds(mks.map((m) => [m.lat, m.lng]));
      mapInstanceRef.current.fitBounds(bounds, { padding: [40, 40], maxZoom: 12 });
    }
  }

  useEffect(() => {
    loadLeaflet().then(() => {
      setTimeout(initMap, 100);
    });
  }, []);

  useEffect(() => {
    renderMarkers(liveData.markers);
  }, [liveData.markers]);

  function fetchMapData(courier?: string, status?: string) {
    setLoading(true);
    const params = new URLSearchParams();
    if (courier && courier !== 'all') params.set('courier', courier);
    if (status && status !== 'all') params.set('status', status);
    axios
      .get(`/waybills/geo-map/api?${params.toString()}`)
      .then(({ data }) => {
        setLiveData({ markers: data.markers, summary: data.summary });
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    fetchMapData(courierFilter, statusFilter);
  }, [courierFilter, statusFilter]);

  function fetchHistory(waybillId: number) {
    setHistoryLoading(true);
    setShowHistory(true);
    axios
      .get(`/waybills/geo-map/${waybillId}/history`)
      .then(({ data }) => setHistoryData(data))
      .catch(() => {})
      .finally(() => setHistoryLoading(false));
  }

  return (
    <AppLayout>
      <Head title="Geolocation Map" />

      <div className="space-y-4 p-6">
        {/* Header */}
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-xl font-bold font-display">Geolocation Map</h1>
            <p className="text-sm text-muted-foreground">
              In-transit waybills with last-known location from courier tracking
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Select value={courierFilter} onValueChange={setCourierFilter}>
              <SelectTrigger className="w-36">
                <SelectValue placeholder="All Couriers" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Couriers</SelectItem>
                <SelectItem value="J&T">J&T Express</SelectItem>
                <SelectItem value="FLASH">Flash Express</SelectItem>
              </SelectContent>
            </Select>
            <Select value={statusFilter} onValueChange={setStatusFilter}>
              <SelectTrigger className="w-40">
                <SelectValue placeholder="All Statuses" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                <SelectItem value="DISPATCHED">Dispatched</SelectItem>
                <SelectItem value="IN_TRANSIT">In Transit</SelectItem>
                <SelectItem value="ARRIVED_HUB">Arrived at Hub</SelectItem>
                <SelectItem value="OUT_FOR_DELIVERY">Out for Delivery</SelectItem>
                <SelectItem value="DELIVERY_FAILED">Delivery Failed</SelectItem>
                <SelectItem value="RETURNING">Returning</SelectItem>
              </SelectContent>
            </Select>
            {loading && <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />}
          </div>
        </div>

        {/* Stats Row */}
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-6">
          <StatChip
            icon={<Truck className="h-4 w-4 text-info" />}
            label="In Transit"
            value={String(stats.total_in_transit)}
          />
          <StatChip
            icon={<MapPin className="h-4 w-4 text-success" />}
            label="With Coordinates"
            value={String(stats.with_coordinates)}
          />
          <StatChip
            icon={<Navigation className="h-4 w-4 text-warning" />}
            label="Coverage"
            value={`${stats.coverage_percent}%`}
          />
          <StatChip
            icon={<Package className="h-4 w-4 text-info" />}
            label="Mapped"
            value={String(liveData.summary.total_mapped)}
          />
          <StatChip
            icon={<Package className="h-4 w-4 text-muted-foreground" />}
            label="Unmapped"
            value={String(liveData.summary.total_unmapped)}
          />
          <StatChip
            icon={<MapPin className="h-4 w-4 text-muted-foreground" />}
            label="With Location"
            value={String(stats.with_location_text)}
          />
        </div>

        <div className="grid gap-4 lg:grid-cols-3">
          {/* Map */}
          <Card className="lg:col-span-2 p-0 overflow-hidden">
            <div ref={mapRef} style={{ height: '600px', width: '100%' }} />
          </Card>

          {/* Side Panel */}
          <div className="space-y-4">
            {/* Status Breakdown */}
            <Card className="p-4 space-y-2">
              <h2 className="text-sm font-semibold">Status Breakdown</h2>
              <div className="space-y-1.5">
                {Object.entries(liveData.summary.status_counts).map(([status, count]) => (
                  <div key={status} className="flex items-center justify-between text-sm">
                    <span className="flex items-center gap-2">
                      <span
                        className="inline-block h-2.5 w-2.5 rounded-full"
                        style={{ backgroundColor: STATUS_COLORS[status] ?? '#6b7280' }}
                      />
                      {status.replace(/_/g, ' ')}
                    </span>
                    <Badge variant="secondary" className="text-xs">
                      {count}
                    </Badge>
                  </div>
                ))}
                {Object.keys(liveData.summary.status_counts).length === 0 && (
                  <p className="text-sm text-muted-foreground py-2 text-center">No data</p>
                )}
              </div>
            </Card>

            {/* Top Cities */}
            <Card className="p-4 space-y-2">
              <h2 className="text-sm font-semibold">Top Cities</h2>
              <div className="space-y-1.5">
                {Object.entries(liveData.summary.top_cities).map(([city, count]) => (
                  <div key={city} className="flex items-center justify-between text-sm">
                    <span className="flex items-center gap-1.5">
                      <MapPin className="h-3 w-3 text-muted-foreground" />
                      {city}
                    </span>
                    <Badge variant="secondary" className="text-xs">
                      {count}
                    </Badge>
                  </div>
                ))}
                {Object.keys(liveData.summary.top_cities).length === 0 && (
                  <p className="text-sm text-muted-foreground py-2 text-center">No data</p>
                )}
              </div>
            </Card>

            {/* Recent Location Updates */}
            <Card className="p-4 space-y-2">
              <h2 className="text-sm font-semibold">Recent Location Updates</h2>
              <div className="space-y-2 max-h-64 overflow-y-auto">
                {stats.recent_updates.map((update) => (
                  <div key={update.id} className="flex items-start gap-2 text-xs">
                    <div
                      className="mt-0.5 inline-block h-2 w-2 rounded-full flex-shrink-0"
                      style={{ backgroundColor: STATUS_COLORS[update.status] ?? '#6b7280' }}
                    />
                    <div className="flex-1 min-w-0">
                      <Link
                        href={`/waybills/${update.id}`}
                        className="font-mono font-medium hover:underline"
                      >
                        {update.waybill_number}
                      </Link>
                      <div className="text-muted-foreground truncate">
                        {update.location || update.city || 'No location'}
                      </div>
                      {update.last_location_at && (
                        <div className="text-muted-foreground/60 flex items-center gap-0.5">
                          <Clock className="h-2.5 w-2.5" />
                          {formatDate(update.last_location_at)}
                        </div>
                      )}
                    </div>
                  </div>
                ))}
                {stats.recent_updates.length === 0 && (
                  <p className="text-sm text-muted-foreground py-2 text-center">No updates</p>
                )}
              </div>
            </Card>
          </div>
        </div>

        {/* Markers Table */}
        <Card className="p-4 space-y-3">
          <h2 className="text-sm font-semibold">
            In-Transit Waybills ({liveData.summary.total_mapped})
          </h2>
          {liveData.markers.length === 0 ? (
            <p className="text-sm text-muted-foreground py-8 text-center">
              No in-transit waybills with location data.
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Waybill #</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Courier</TableHead>
                  <TableHead>Receiver</TableHead>
                  <TableHead>City</TableHead>
                  <TableHead className="text-right">COD</TableHead>
                  <TableHead>Last Location</TableHead>
                  <TableHead></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {liveData.markers.slice(0, 50).map((m) => (
                  <TableRow key={m.id}>
                    <TableCell>
                      <Link
                        href={`/waybills/${m.id}`}
                        className="font-mono text-sm font-medium hover:underline"
                      >
                        {m.waybill_number}
                      </Link>
                    </TableCell>
                    <TableCell>
                      <Badge
                        variant="secondary"
                        className="text-xs"
                        style={{
                          backgroundColor: `${STATUS_COLORS[m.status] ?? '#6b7280'}20`,
                          color: STATUS_COLORS[m.status] ?? '#6b7280',
                        }}
                      >
                        {m.status_label}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-sm">{m.courier}</TableCell>
                    <TableCell className="text-sm">{m.receiver_name}</TableCell>
                    <TableCell className="text-sm">{m.city || '—'}</TableCell>
                    <TableCell className="text-right text-sm font-medium">
                      {formatPeso(m.cod_amount)}
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground max-w-[200px] truncate">
                      {m.location_description || '—'}
                    </TableCell>
                    <TableCell>
                      <Button size="sm" variant="ghost" onClick={() => fetchHistory(m.id)}>
                        History
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </Card>
      </div>

      {/* History Modal */}
      {showHistory && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
          onClick={() => setShowHistory(false)}
        >
          <div
            className="bg-background rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[80vh] overflow-y-auto"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between p-4 border-b">
              <h3 className="text-sm font-semibold">Location History</h3>
              <Button size="sm" variant="ghost" onClick={() => setShowHistory(false)}>
                <X className="h-4 w-4" />
              </Button>
            </div>
            <div className="p-4">
              {historyLoading ? (
                <div className="flex items-center justify-center py-8">
                  <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                </div>
              ) : historyData ? (
                <div className="space-y-4">
                  <div className="flex items-center gap-3 pb-3 border-b">
                    <div>
                      <div className="font-mono font-medium">
                        {historyData.waybill.waybill_number}
                      </div>
                      <div className="text-sm text-muted-foreground">
                        {historyData.waybill.courier} · {historyData.waybill.receiver_name} ·{' '}
                        {historyData.waybill.city || 'N/A'}
                      </div>
                    </div>
                  </div>
                  <div className="space-y-2">
                    {historyData.history.map((h: any, idx: number) => (
                      <div key={h.id} className="flex gap-3">
                        <div className="flex flex-col items-center">
                          <div
                            className="h-3 w-3 rounded-full border-2 border-white shadow"
                            style={{ backgroundColor: STATUS_COLORS[h.status] ?? '#6b7280' }}
                          />
                          {idx < historyData.history.length - 1 && (
                            <div className="w-0.5 h-8 bg-border" />
                          )}
                        </div>
                        <div className="flex-1 pb-2">
                          <div className="text-sm font-medium">{h.status.replace(/_/g, ' ')}</div>
                          {h.location && (
                            <div className="text-xs text-muted-foreground flex items-center gap-1">
                              <MapPin className="h-3 w-3" />
                              {h.location}
                            </div>
                          )}
                          {h.reason && (
                            <div className="text-xs text-muted-foreground/70">{h.reason}</div>
                          )}
                          {h.tracked_at && (
                            <div className="text-xs text-muted-foreground/60 flex items-center gap-0.5 mt-0.5">
                              <Clock className="h-2.5 w-2.5" />
                              {formatDate(h.tracked_at)}
                            </div>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                  <Link href={`/waybills/${historyData.waybill.id}`}>
                    <Button variant="outline" size="sm">
                      View Waybill Details
                      <ChevronRight className="ml-1 h-4 w-4" />
                    </Button>
                  </Link>
                </div>
              ) : (
                <p className="text-sm text-muted-foreground py-4 text-center">No history data.</p>
              )}
            </div>
          </div>
        </div>
      )}
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
