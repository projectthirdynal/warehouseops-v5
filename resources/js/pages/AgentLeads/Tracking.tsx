import { useState } from 'react';
import { router } from '@inertiajs/react';
import AgentLayout from '@/layouts/AgentLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Search,
  Package,
  Truck,
  CheckCircle,
  XCircle,
  Clock,
  MapPin,
  AlertTriangle,
  ArrowRight,
} from 'lucide-react';
import { formatCurrency, formatDateTime } from '@/lib/utils';

interface TrackingEvent {
  status: string;
  previous_status: string | null;
  reason: string | null;
  location: string | null;
  tracked_at: string;
}

interface WaybillResult {
  id: number;
  waybill_number: string;
  status: string;
  courier_provider: string;
  receiver_name: string;
  receiver_phone: string;
  city: string | null;
  state: string | null;
  item_name: string | null;
  cod_amount: number | null;
  dispatched_at: string | null;
  delivered_at: string | null;
  returned_at: string | null;
  created_at: string;
  tracking_history: TrackingEvent[];
}

interface Props {
  waybill: WaybillResult | null;
  search: string;
  notFound: boolean;
}

const statusConfig: Record<string, { label: string; color: string; icon: React.ReactNode }> = {
  PENDING:          { label: 'Pending',          color: 'bg-gray-100 text-gray-800',   icon: <Clock className="h-4 w-4" /> },
  DISPATCHED:       { label: 'Dispatched',       color: 'bg-blue-100 text-blue-800',   icon: <Truck className="h-4 w-4" /> },
  PICKED_UP:        { label: 'Picked Up',        color: 'bg-blue-100 text-blue-800',   icon: <Package className="h-4 w-4" /> },
  IN_TRANSIT:       { label: 'In Transit',       color: 'bg-indigo-100 text-indigo-800', icon: <Truck className="h-4 w-4" /> },
  ARRIVED_HUB:      { label: 'At Hub',           color: 'bg-purple-100 text-purple-800', icon: <MapPin className="h-4 w-4" /> },
  OUT_FOR_DELIVERY: { label: 'Out for Delivery', color: 'bg-yellow-100 text-yellow-800', icon: <Truck className="h-4 w-4" /> },
  DELIVERY_FAILED:  { label: 'Delivery Failed',  color: 'bg-orange-100 text-orange-800', icon: <AlertTriangle className="h-4 w-4" /> },
  DELIVERED:        { label: 'Delivered',         color: 'bg-green-100 text-green-800', icon: <CheckCircle className="h-4 w-4" /> },
  RETURNING:        { label: 'Returning',         color: 'bg-orange-100 text-orange-800', icon: <Truck className="h-4 w-4" /> },
  RETURNED:         { label: 'Returned',          color: 'bg-red-100 text-red-800',     icon: <XCircle className="h-4 w-4" /> },
  CANCELLED:        { label: 'Cancelled',         color: 'bg-gray-100 text-gray-600',   icon: <XCircle className="h-4 w-4" /> },
};

export default function AgentTracking({ waybill, search, notFound }: Props) {
  const [query, setQuery] = useState(search || '');

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    if (!query.trim()) return;
    router.get('/agent/tracking', { search: query.trim() }, { preserveState: true });
  };

  const cfg = waybill ? (statusConfig[waybill.status] ?? statusConfig.PENDING) : null;

  return (
    <AgentLayout>
      <div className="max-w-2xl mx-auto space-y-6">
        {/* Header */}
        <div className="text-center space-y-2">
          <h1 className="text-2xl font-bold">Track Waybill</h1>
          <p className="text-sm text-muted-foreground">
            Enter a tracking number to check shipment status
          </p>
        </div>

        {/* Search */}
        <form onSubmit={handleSearch}>
          <div className="flex gap-2">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-5 w-5 text-muted-foreground" />
              <input
                type="text"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Enter tracking number (e.g. PH0112XXXXXX)"
                className="w-full pl-11 pr-4 py-3 border rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary/20"
                autoFocus
              />
            </div>
            <Button type="submit" size="lg" disabled={!query.trim()}>
              Track
            </Button>
          </div>
        </form>

        {/* Not found */}
        {notFound && (
          <Card className="border-orange-200 bg-orange-50">
            <CardContent className="p-6 text-center">
              <AlertTriangle className="h-8 w-8 text-orange-500 mx-auto mb-2" />
              <p className="font-medium text-orange-800">Waybill not found</p>
              <p className="text-sm text-orange-600 mt-1">
                No waybill found for "{search}". Check the tracking number and try again.
              </p>
            </CardContent>
          </Card>
        )}

        {/* Result */}
        {waybill && cfg && (
          <div className="space-y-4">
            {/* Status card */}
            <Card>
              <CardContent className="p-6">
                <div className="flex items-center justify-between mb-4">
                  <div>
                    <p className="text-xs text-muted-foreground uppercase tracking-wide">Tracking Number</p>
                    <p className="text-lg font-mono font-bold text-primary">{waybill.waybill_number}</p>
                  </div>
                  <Badge className={`${cfg.color} text-sm gap-1.5 px-3 py-1.5`}>
                    {cfg.icon}
                    {cfg.label}
                  </Badge>
                </div>

                <div className="grid grid-cols-2 gap-4 text-sm">
                  <div>
                    <span className="text-muted-foreground">Receiver</span>
                    <p className="font-medium">{waybill.receiver_name}</p>
                    <p className="text-xs text-muted-foreground">{waybill.receiver_phone}</p>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Destination</span>
                    <p className="font-medium">
                      {[waybill.city, waybill.state].filter(Boolean).join(', ') || 'N/A'}
                    </p>
                  </div>
                  {waybill.item_name && (
                    <div>
                      <span className="text-muted-foreground">Item</span>
                      <p className="font-medium">{waybill.item_name}</p>
                    </div>
                  )}
                  {waybill.cod_amount != null && waybill.cod_amount > 0 && (
                    <div>
                      <span className="text-muted-foreground">COD Amount</span>
                      <p className="font-semibold text-green-600">{formatCurrency(waybill.cod_amount)}</p>
                    </div>
                  )}
                  <div>
                    <span className="text-muted-foreground">Courier</span>
                    <p className="font-medium">{waybill.courier_provider}</p>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Created</span>
                    <p className="font-medium">{formatDateTime(waybill.created_at)}</p>
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Timeline */}
            <Card>
              <CardHeader>
                <CardTitle className="text-base">Tracking History</CardTitle>
              </CardHeader>
              <CardContent>
                {waybill.tracking_history.length === 0 ? (
                  <p className="text-sm text-muted-foreground text-center py-4">
                    No tracking events recorded yet.
                  </p>
                ) : (
                  <div className="relative">
                    {/* Timeline line */}
                    <div className="absolute left-[19px] top-2 bottom-2 w-0.5 bg-muted" />

                    <div className="space-y-4">
                      {waybill.tracking_history.map((event, i) => {
                        const eventCfg = statusConfig[event.status] ?? statusConfig.PENDING;
                        const isFirst = i === 0;
                        return (
                          <div key={i} className="relative flex gap-4 items-start">
                            {/* Dot */}
                            <div className={`relative z-10 flex items-center justify-center w-10 h-10 rounded-full border-2 ${
                              isFirst ? 'bg-primary border-primary text-primary-foreground' : 'bg-background border-muted-foreground/30'
                            }`}>
                              <div className="scale-75">{eventCfg.icon}</div>
                            </div>

                            {/* Content */}
                            <div className="flex-1 min-w-0 pb-2">
                              <div className="flex items-center gap-2">
                                <span className={`font-medium text-sm ${isFirst ? 'text-primary' : ''}`}>
                                  {eventCfg.label}
                                </span>
                                {event.previous_status && (
                                  <span className="text-[10px] text-muted-foreground flex items-center gap-1">
                                    from {event.previous_status} <ArrowRight className="h-2.5 w-2.5" />
                                  </span>
                                )}
                              </div>
                              {event.reason && (
                                <p className="text-xs text-muted-foreground mt-0.5">{event.reason}</p>
                              )}
                              {event.location && (
                                <p className="text-xs text-muted-foreground flex items-center gap-1 mt-0.5">
                                  <MapPin className="h-3 w-3" />{event.location}
                                </p>
                              )}
                              <p className="text-[10px] text-muted-foreground mt-1">
                                {formatDateTime(event.tracked_at)}
                              </p>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        )}

        {/* Empty state */}
        {!waybill && !notFound && !search && (
          <Card>
            <CardContent className="p-12 text-center">
              <Package className="h-12 w-12 text-muted-foreground/30 mx-auto mb-4" />
              <p className="text-muted-foreground">
                Enter a waybill tracking number above to view shipment details and tracking history.
              </p>
            </CardContent>
          </Card>
        )}
      </div>
    </AgentLayout>
  );
}
