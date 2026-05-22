import { useState, useEffect } from 'react';
import {
  X,
  User,
  MapPin,
  TrendingUp,
  TrendingDown,
  ShieldAlert,
  ShieldCheck,
  Package,
  CheckCircle,
  XCircle,
  Clock,
  AlertTriangle,
  Loader2,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { formatCurrency, formatDateTime } from '@/lib/utils';

interface CustomerProfile {
  id: number;
  name: string;
  phone: string;
  canonical_address: string | null;
  total_orders: number;
  successful_orders: number;
  returned_orders: number;
  success_rate: number;
  total_revenue: number;
  risk_level: 'LOW' | 'MEDIUM' | 'HIGH' | 'BLACKLISTED';
  is_blacklisted: boolean;
  blacklist_reason: string | null;
}

interface WaybillRecord {
  id: number;
  waybill_number: string;
  status: string;
  item_name: string | null;
  amount: number | null;
  city: string | null;
  state: string | null;
  barangay: string | null;
  address: string | null;
  rts_reason: string | null;
  delivered_at: string | null;
  returned_at: string | null;
  created_at: string;
}

interface CustomerHistoryData {
  customer: CustomerProfile | null;
  waybills: WaybillRecord[];
  message?: string;
}

interface Props {
  leadId: number;
  isOpen: boolean;
  onClose: () => void;
}

const statusConfig: Record<string, { label: string; icon: React.ReactNode; color: string }> = {
  DELIVERED:   { label: 'Delivered',   icon: <CheckCircle className="h-3.5 w-3.5" />, color: 'bg-green-100 text-green-800 border-green-200' },
  RETURNED:    { label: 'Returned',    icon: <XCircle className="h-3.5 w-3.5" />,     color: 'bg-red-100 text-red-800 border-red-200' },
  DISPATCHED:  { label: 'Dispatched',  icon: <Package className="h-3.5 w-3.5" />,     color: 'bg-blue-100 text-blue-800 border-blue-200' },
  IN_TRANSIT:  { label: 'In Transit',  icon: <Package className="h-3.5 w-3.5" />,     color: 'bg-blue-100 text-blue-800 border-blue-200' },
  OUT_FOR_DELIVERY: { label: 'Out for Delivery', icon: <Package className="h-3.5 w-3.5" />, color: 'bg-yellow-100 text-yellow-800 border-yellow-200' },
  CANCELLED:   { label: 'Cancelled',   icon: <XCircle className="h-3.5 w-3.5" />,     color: 'bg-gray-100 text-gray-600 border-gray-200' },
  PENDING:     { label: 'Pending',     icon: <Clock className="h-3.5 w-3.5" />,       color: 'bg-gray-100 text-gray-600 border-gray-200' },
};

const riskConfig: Record<string, { label: string; color: string; icon: React.ReactNode }> = {
  LOW:         { label: 'Low Risk',    color: 'bg-green-100 text-green-800',  icon: <ShieldCheck className="h-4 w-4" /> },
  MEDIUM:      { label: 'Medium Risk', color: 'bg-yellow-100 text-yellow-800', icon: <ShieldAlert className="h-4 w-4" /> },
  HIGH:        { label: 'High Risk',   color: 'bg-orange-100 text-orange-800', icon: <ShieldAlert className="h-4 w-4" /> },
  BLACKLISTED: { label: 'Blacklisted', color: 'bg-red-100 text-red-800',      icon: <ShieldAlert className="h-4 w-4" /> },
};

export function CustomerHistoryModal({ leadId, isOpen, onClose }: Props) {
  const [data, setData] = useState<CustomerHistoryData | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!isOpen) return;
    setLoading(true);
    setError(null);
    setData(null);

    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

    fetch(`/api/agent/leads/${leadId}/customer-history`, {
      headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
    })
      .then((res) => res.json())
      .then((json) => setData(json))
      .catch(() => setError('Failed to load customer history.'))
      .finally(() => setLoading(false));
  }, [isOpen, leadId]);

  if (!isOpen) return null;

  const customer = data?.customer;
  const waybills = data?.waybills ?? [];
  const risk = customer ? riskConfig[customer.risk_level] ?? riskConfig.LOW : null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      {/* Backdrop */}
      <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} />

      {/* Panel */}
      <div className="relative z-10 flex flex-col w-full max-w-2xl max-h-[90vh] rounded-2xl bg-background shadow-2xl border overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b bg-card shrink-0">
          <div className="flex items-center gap-2">
            <User className="h-5 w-5 text-primary" />
            <h2 className="text-base font-semibold">Customer History</h2>
          </div>
          <Button variant="ghost" size="icon" onClick={onClose}>
            <X className="h-4 w-4" />
          </Button>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-5 space-y-5">
          {loading && (
            <div className="flex items-center justify-center py-16 text-muted-foreground">
              <Loader2 className="h-6 w-6 animate-spin mr-2" />
              Loading...
            </div>
          )}

          {error && (
            <div className="flex items-center gap-2 p-4 bg-red-50 rounded-xl text-red-700 text-sm">
              <AlertTriangle className="h-4 w-4 shrink-0" />
              {error}
            </div>
          )}

          {data?.message && !customer && (
            <div className="flex items-center gap-2 p-4 bg-muted rounded-xl text-muted-foreground text-sm">
              <User className="h-4 w-4 shrink-0" />
              {data.message}
            </div>
          )}

          {customer && (
            <>
              {/* Blacklist banner */}
              {customer.is_blacklisted && (
                <div className="flex items-start gap-3 p-4 rounded-xl bg-red-50 border border-red-200">
                  <ShieldAlert className="h-5 w-5 text-red-600 shrink-0 mt-0.5" />
                  <div>
                    <p className="text-sm font-semibold text-red-800">Customer is Blacklisted</p>
                    {customer.blacklist_reason && (
                      <p className="text-xs text-red-700 mt-0.5">{customer.blacklist_reason}</p>
                    )}
                  </div>
                </div>
              )}

              {/* Profile card */}
              <div className="rounded-xl border bg-card p-4 space-y-3">
                <div className="flex items-start justify-between">
                  <div>
                    <p className="font-semibold text-base">{customer.name}</p>
                    <p className="text-sm text-muted-foreground">{customer.phone}</p>
                    {customer.canonical_address && (
                      <p className="text-xs text-muted-foreground flex items-center gap-1 mt-1">
                        <MapPin className="h-3 w-3" />
                        {customer.canonical_address}
                      </p>
                    )}
                  </div>
                  {risk && (
                    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${risk.color}`}>
                      {risk.icon}
                      {risk.label}
                    </span>
                  )}
                </div>

                {/* Stats row */}
                <div className="grid grid-cols-4 gap-3 pt-2 border-t">
                  <div className="text-center">
                    <p className="text-xl font-bold">{customer.total_orders}</p>
                    <p className="text-[10px] text-muted-foreground uppercase tracking-wide">Orders</p>
                  </div>
                  <div className="text-center">
                    <p className="text-xl font-bold text-green-600">{customer.successful_orders}</p>
                    <p className="text-[10px] text-muted-foreground uppercase tracking-wide">Delivered</p>
                  </div>
                  <div className="text-center">
                    <p className="text-xl font-bold text-red-500">{customer.returned_orders}</p>
                    <p className="text-[10px] text-muted-foreground uppercase tracking-wide">Returned</p>
                  </div>
                  <div className="text-center">
                    <p className={`text-xl font-bold ${customer.success_rate >= 70 ? 'text-green-600' : customer.success_rate >= 40 ? 'text-yellow-600' : 'text-red-500'}`}>
                      {customer.success_rate}%
                    </p>
                    <p className="text-[10px] text-muted-foreground uppercase tracking-wide">Success</p>
                  </div>
                </div>

                {/* Success rate bar */}
                <div className="space-y-1">
                  <div className="flex justify-between text-xs text-muted-foreground">
                    <span className="flex items-center gap-1">
                      <TrendingUp className="h-3 w-3 text-green-500" />
                      Delivery success rate
                    </span>
                    {customer.total_revenue > 0 && (
                      <span className="font-medium text-foreground">
                        {formatCurrency(customer.total_revenue)} total
                      </span>
                    )}
                  </div>
                  <div className="h-2 w-full rounded-full bg-muted overflow-hidden">
                    <div
                      className={`h-full rounded-full transition-all ${
                        customer.success_rate >= 70
                          ? 'bg-green-500'
                          : customer.success_rate >= 40
                          ? 'bg-yellow-500'
                          : 'bg-red-500'
                      }`}
                      style={{ width: `${Math.min(100, customer.success_rate)}%` }}
                    />
                  </div>
                </div>
              </div>

              {/* Order history */}
              <div>
                <h3 className="text-sm font-semibold mb-3 flex items-center gap-2">
                  <Package className="h-4 w-4 text-muted-foreground" />
                  Order History
                  <Badge variant="outline" className="text-xs">{waybills.length}</Badge>
                </h3>

                {waybills.length === 0 ? (
                  <div className="text-center py-8 text-sm text-muted-foreground bg-muted/30 rounded-xl">
                    No waybill records found for this customer.
                  </div>
                ) : (
                  <div className="space-y-2">
                    {waybills.map((w) => {
                      const cfg = statusConfig[w.status] ?? { label: w.status, icon: <Package className="h-3.5 w-3.5" />, color: 'bg-gray-100 text-gray-600 border-gray-200' };
                      return (
                        <div
                          key={w.id}
                          className="flex items-start justify-between p-3 rounded-xl border bg-card hover:bg-accent/30 transition-colors"
                        >
                          <div className="space-y-1 min-w-0 flex-1">
                            <div className="flex items-center gap-2 flex-wrap">
                              <span className="font-mono text-xs font-semibold text-primary">
                                {w.waybill_number}
                              </span>
                              <span className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium ${cfg.color}`}>
                                {cfg.icon}
                                {cfg.label}
                              </span>
                            </div>
                            {w.item_name && (
                              <p className="text-xs text-muted-foreground truncate">{w.item_name}</p>
                            )}
                            {w.rts_reason && (
                              <p className="text-xs text-red-600 flex items-center gap-1">
                                <TrendingDown className="h-3 w-3" />
                                {w.rts_reason}
                              </p>
                            )}
                            <p className="text-[10px] text-muted-foreground">
                              {[w.barangay, w.city, w.state].filter(Boolean).join(', ')}
                            </p>
                          </div>
                          <div className="text-right ml-3 shrink-0">
                            {w.amount != null && (
                              <p className="text-sm font-semibold">{formatCurrency(w.amount)}</p>
                            )}
                            <p className="text-[10px] text-muted-foreground">
                              {w.delivered_at
                                ? formatDateTime(w.delivered_at)
                                : w.returned_at
                                ? formatDateTime(w.returned_at)
                                : formatDateTime(w.created_at)}
                            </p>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            </>
          )}
        </div>

        {/* Footer */}
        <div className="px-5 py-3 border-t bg-card shrink-0">
          <p className="text-[11px] text-muted-foreground">
            Order history is read-only. Contact a supervisor to flag data issues.
          </p>
        </div>
      </div>
    </div>
  );
}
