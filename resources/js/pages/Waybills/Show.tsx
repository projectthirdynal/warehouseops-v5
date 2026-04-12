import { Head, Link } from '@inertiajs/react';
import {
  ArrowLeft,
  Package,
  User,
  Phone,
  MapPin,
  Truck,
  Clock,
  CheckCircle,
  XCircle,
  Star,
  TrendingUp,
  AlertTriangle,
  Calendar,
  DollarSign,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

interface Waybill {
  id: number;
  waybill_number: string;
  status: string;
  receiver_name: string;
  receiver_phone: string;
  receiver_address: string;
  city: string;
  state: string;
  barangay?: string;
  cod_amount?: number;
  shipping_cost?: number;
  remarks?: string;
  rts_reason?: string;
  item_name?: string;
  item_qty?: number;
  courier_provider: string;
  creator_code?: string;
  express_type?: string;
  sender_name?: string;
  sender_phone?: string;
  created_at: string;
  submitted_at?: string;
  dispatched_at?: string;
  delivered_at?: string;
  returned_at?: string;
  uploaded_by?: { name: string };
}

interface OrderHistoryItem {
  id: number;
  waybill_number: string;
  status: string;
  cod_amount?: number;
  remarks?: string;
  created_at: string;
  delivered_at?: string;
  returned_at?: string;
}

interface Customer {
  id: number;
  phone: string;
  name: string;
  total_orders: number;
  successful_orders: number;
  returned_orders: number;
  success_rate: number;
  risk_level: string;
  is_blacklisted: boolean;
}

interface CustomerStats {
  total_orders: number;
  delivered: number;
  returned: number;
  pending: number;
  total_cod: number;
  success_rate: number;
}

interface CustomerRating {
  score: number;
  label: string;
  color: string;
}

interface Props {
  waybill: Waybill;
  customer: Customer | null;
  orderHistory: OrderHistoryItem[];
  customerStats: CustomerStats;
  customerRating: CustomerRating;
}

const statusConfig: Record<string, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline'; icon: typeof Package }> = {
  PENDING: { label: 'Pending', variant: 'secondary', icon: Clock },
  DISPATCHED: { label: 'Dispatched', variant: 'default', icon: Truck },
  PICKED_UP: { label: 'Picked Up', variant: 'default', icon: Package },
  IN_TRANSIT: { label: 'In Transit', variant: 'default', icon: Truck },
  OUT_FOR_DELIVERY: { label: 'Out for Delivery', variant: 'default', icon: Truck },
  AT_WAREHOUSE: { label: 'At Warehouse', variant: 'outline', icon: Package },
  DELIVERED: { label: 'Delivered', variant: 'default', icon: CheckCircle },
  RETURNED: { label: 'Returned', variant: 'destructive', icon: XCircle },
  CANCELLED: { label: 'Cancelled', variant: 'destructive', icon: XCircle },
};

const ratingColors: Record<string, string> = {
  green: 'bg-green-100 text-green-800 border-green-200',
  blue: 'bg-blue-100 text-blue-800 border-blue-200',
  yellow: 'bg-yellow-100 text-yellow-800 border-yellow-200',
  orange: 'bg-orange-100 text-orange-800 border-orange-200',
  red: 'bg-red-100 text-red-800 border-red-200',
};

export default function WaybillShow({ waybill, customer, orderHistory, customerStats, customerRating }: Props) {
  const config = statusConfig[waybill.status] || statusConfig.PENDING;
  const StatusIcon = config.icon;

  return (
    <AppLayout>
      <Head title={`Waybill ${waybill.waybill_number}`} />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Link href="/waybills">
              <Button variant="outline" size="icon">
                <ArrowLeft className="h-4 w-4" />
              </Button>
            </Link>
            <div>
              <h1 className="text-2xl font-bold tracking-tight font-mono">{waybill.waybill_number}</h1>
              <p className="text-muted-foreground">
                {waybill.submitted_at ? `Submitted ${new Date(waybill.submitted_at).toLocaleDateString()}` : `Added ${new Date(waybill.created_at).toLocaleDateString()}`}
              </p>
            </div>
          </div>
          <Badge variant={config.variant} className="gap-1 text-sm px-3 py-1">
            <StatusIcon className="h-4 w-4" />
            {config.label}
          </Badge>
        </div>

        <div className="grid gap-6 lg:grid-cols-3">
          {/* Main Content - 2 columns */}
          <div className="lg:col-span-2 space-y-6">
            {/* Waybill Details */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Package className="h-5 w-5" />
                  Shipment Details
                </CardTitle>
              </CardHeader>
              <CardContent className="grid gap-4 md:grid-cols-2">
                <div className="space-y-3">
                  <div>
                    <p className="text-sm text-muted-foreground">Receiver</p>
                    <p className="font-medium flex items-center gap-2">
                      <User className="h-4 w-4 text-muted-foreground" />
                      {waybill.receiver_name}
                    </p>
                  </div>
                  <div>
                    <p className="text-sm text-muted-foreground">Phone</p>
                    <p className="font-medium flex items-center gap-2">
                      <Phone className="h-4 w-4 text-muted-foreground" />
                      {waybill.receiver_phone}
                    </p>
                  </div>
                  <div>
                    <p className="text-sm text-muted-foreground">Address</p>
                    <p className="font-medium flex items-start gap-2">
                      <MapPin className="h-4 w-4 text-muted-foreground mt-0.5" />
                      <span>
                        {waybill.receiver_address}
                        <br />
                        <span className="text-sm text-muted-foreground">
                          {[waybill.barangay, waybill.city, waybill.state].filter(Boolean).join(', ')}
                        </span>
                      </span>
                    </p>
                  </div>
                </div>
                <div className="space-y-3">
                  <div>
                    <p className="text-sm text-muted-foreground">Item</p>
                    <p className="font-medium">{waybill.item_name || '-'}</p>
                  </div>
                  <div>
                    <p className="text-sm text-muted-foreground">Remarks</p>
                    <p className="font-medium">{waybill.remarks || '-'}</p>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <p className="text-sm text-muted-foreground">COD Amount</p>
                      <p className="font-medium text-lg">₱{waybill.cod_amount?.toLocaleString() || '0'}</p>
                    </div>
                    <div>
                      <p className="text-sm text-muted-foreground">Shipping</p>
                      <p className="font-medium">₱{waybill.shipping_cost?.toLocaleString() || '0'}</p>
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Order History */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Clock className="h-5 w-5" />
                  Customer Order History
                  <Badge variant="secondary" className="ml-2">{orderHistory.length} orders</Badge>
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="overflow-x-auto">
                  <table className="w-full">
                    <thead>
                      <tr className="border-b bg-muted/50">
                        <th className="h-10 px-3 text-left text-sm font-medium text-muted-foreground">Waybill #</th>
                        <th className="h-10 px-3 text-left text-sm font-medium text-muted-foreground">Status</th>
                        <th className="h-10 px-3 text-left text-sm font-medium text-muted-foreground">Remarks</th>
                        <th className="h-10 px-3 text-left text-sm font-medium text-muted-foreground">COD</th>
                        <th className="h-10 px-3 text-left text-sm font-medium text-muted-foreground">Date</th>
                      </tr>
                    </thead>
                    <tbody>
                      {orderHistory.map((order) => {
                        const orderConfig = statusConfig[order.status] || statusConfig.PENDING;
                        const isCurrentOrder = order.id === waybill.id;
                        return (
                          <tr
                            key={order.id}
                            className={`border-b transition-colors ${isCurrentOrder ? 'bg-blue-50' : 'hover:bg-muted/50'}`}
                          >
                            <td className="p-3 font-mono text-sm">
                              {isCurrentOrder ? (
                                <span className="font-bold">{order.waybill_number}</span>
                              ) : (
                                <Link href={`/waybills/${order.id}`} className="text-blue-600 hover:underline">
                                  {order.waybill_number}
                                </Link>
                              )}
                              {isCurrentOrder && <Badge variant="outline" className="ml-2 text-xs">Current</Badge>}
                            </td>
                            <td className="p-3">
                              <Badge variant={orderConfig.variant} className="text-xs">
                                {orderConfig.label}
                              </Badge>
                            </td>
                            <td className="p-3 text-sm max-w-[200px] truncate" title={order.remarks || ''}>
                              {order.remarks || '-'}
                            </td>
                            <td className="p-3 text-sm font-medium">₱{order.cod_amount?.toLocaleString() || '0'}</td>
                            <td className="p-3 text-sm text-muted-foreground">
                              {new Date(order.created_at).toLocaleDateString()}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </CardContent>
            </Card>
          </div>

          {/* Sidebar - Customer Info */}
          <div className="space-y-6">
            {/* Customer Rating Card */}
            <Card className={`border-2 ${ratingColors[customerRating.color]}`}>
              <CardHeader className="pb-2">
                <CardTitle className="flex items-center justify-between">
                  <span className="flex items-center gap-2">
                    <Star className="h-5 w-5" />
                    Customer Rating
                  </span>
                  <span className="text-2xl font-bold">{customerRating.score}/5</span>
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="flex items-center justify-between">
                  <span className="text-lg font-semibold">{customerRating.label}</span>
                  <div className="flex gap-1">
                    {[1, 2, 3, 4, 5].map((star) => (
                      <Star
                        key={star}
                        className={`h-5 w-5 ${star <= customerRating.score ? 'fill-current' : 'opacity-30'}`}
                      />
                    ))}
                  </div>
                </div>
                <p className="text-sm mt-2 opacity-80">
                  Based on {customerStats.success_rate}% success rate
                </p>
              </CardContent>
            </Card>

            {/* Customer Stats */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <TrendingUp className="h-5 w-5" />
                  Customer Statistics
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div className="text-center p-3 bg-muted rounded-lg">
                    <p className="text-2xl font-bold">{customerStats.total_orders}</p>
                    <p className="text-xs text-muted-foreground">Total Orders</p>
                  </div>
                  <div className="text-center p-3 bg-green-50 rounded-lg">
                    <p className="text-2xl font-bold text-green-600">{customerStats.delivered}</p>
                    <p className="text-xs text-muted-foreground">Delivered</p>
                  </div>
                  <div className="text-center p-3 bg-red-50 rounded-lg">
                    <p className="text-2xl font-bold text-red-600">{customerStats.returned}</p>
                    <p className="text-xs text-muted-foreground">Returned</p>
                  </div>
                  <div className="text-center p-3 bg-yellow-50 rounded-lg">
                    <p className="text-2xl font-bold text-yellow-600">{customerStats.pending}</p>
                    <p className="text-xs text-muted-foreground">Pending</p>
                  </div>
                </div>

                <div className="pt-4 border-t">
                  <div className="flex justify-between items-center mb-2">
                    <span className="text-sm text-muted-foreground">Success Rate</span>
                    <span className="font-bold text-lg">{customerStats.success_rate}%</span>
                  </div>
                  <div className="w-full bg-muted rounded-full h-2">
                    <div
                      className={`h-2 rounded-full ${
                        customerStats.success_rate >= 75 ? 'bg-green-500' :
                        customerStats.success_rate >= 50 ? 'bg-yellow-500' : 'bg-red-500'
                      }`}
                      style={{ width: `${customerStats.success_rate}%` }}
                    />
                  </div>
                </div>

                <div className="pt-4 border-t">
                  <div className="flex justify-between items-center">
                    <span className="text-sm text-muted-foreground flex items-center gap-1">
                      <DollarSign className="h-4 w-4" />
                      Total COD Value
                    </span>
                    <span className="font-bold text-lg">₱{customerStats.total_cod.toLocaleString()}</span>
                  </div>
                </div>

                {customer?.is_blacklisted && (
                  <div className="pt-4 border-t">
                    <div className="flex items-center gap-2 text-red-600 bg-red-50 p-3 rounded-lg">
                      <AlertTriangle className="h-5 w-5" />
                      <span className="font-medium">Customer is Blacklisted</span>
                    </div>
                  </div>
                )}
              </CardContent>
            </Card>

            {/* Shipment Timeline */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Calendar className="h-5 w-5" />
                  Timeline
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="space-y-3">
                  {waybill.submitted_at && (
                    <div className="flex items-start gap-3">
                      <div className="w-2 h-2 mt-2 rounded-full bg-blue-500" />
                      <div>
                        <p className="font-medium text-sm">Submitted to Courier</p>
                        <p className="text-xs text-muted-foreground">
                          {new Date(waybill.submitted_at).toLocaleString()}
                        </p>
                      </div>
                    </div>
                  )}
                  {waybill.dispatched_at && (
                    <div className="flex items-start gap-3">
                      <div className="w-2 h-2 mt-2 rounded-full bg-orange-500" />
                      <div>
                        <p className="font-medium text-sm">Dispatched</p>
                        <p className="text-xs text-muted-foreground">
                          {new Date(waybill.dispatched_at).toLocaleString()}
                        </p>
                      </div>
                    </div>
                  )}
                  {waybill.delivered_at && (
                    <div className="flex items-start gap-3">
                      <div className="w-2 h-2 mt-2 rounded-full bg-green-500" />
                      <div>
                        <p className="font-medium text-sm">Delivered</p>
                        <p className="text-xs text-muted-foreground">
                          {new Date(waybill.delivered_at).toLocaleString()}
                        </p>
                      </div>
                    </div>
                  )}
                  {waybill.returned_at && (
                    <div className="flex items-start gap-3">
                      <div className="w-2 h-2 mt-2 rounded-full bg-red-500" />
                      <div>
                        <p className="font-medium text-sm">Returned</p>
                        <p className="text-xs text-muted-foreground">
                          {new Date(waybill.returned_at).toLocaleString()}
                        </p>
                        {waybill.rts_reason && (
                          <p className="text-xs text-red-600 mt-1">{waybill.rts_reason}</p>
                        )}
                      </div>
                    </div>
                  )}
                </div>
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
