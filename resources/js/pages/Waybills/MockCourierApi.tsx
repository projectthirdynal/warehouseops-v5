import { Head } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import {
  Package,
  Plus,
  Trash2,
  RefreshCw,
  Play,
  XCircle,
  Send,
  Search,
  Loader2,
  Zap,
  Eye,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
  DialogTrigger,
} from '@/components/ui/dialog';

interface MockOrder {
  tracking_number: string;
  status: string;
  status_index: number;
  receiver_name: string;
  receiver_phone: string;
  receiver_address: string;
  receiver_city: string;
  item_name: string;
  item_qty: number;
  cod_amount: number;
  weight: number;
  waybill_id: number | null;
  created_at: string;
  tracking_history: Array<{
    status: string;
    location: string;
    description: string;
    timestamp: string;
  }>;
}

interface Props {
  orders: MockOrder[];
  totalOrders: number;
}

const STATUS_COLORS: Record<string, string> = {
  PENDING: 'bg-yellow-100 text-yellow-800',
  DISPATCHED: 'bg-blue-100 text-blue-800',
  PICKED_UP: 'bg-blue-100 text-blue-800',
  IN_TRANSIT: 'bg-cyan-100 text-cyan-800',
  ARRIVED_HUB: 'bg-cyan-100 text-cyan-800',
  OUT_FOR_DELIVERY: 'bg-amber-100 text-amber-800',
  DELIVERY_FAILED: 'bg-red-100 text-red-800',
  DELIVERED: 'bg-green-100 text-green-800',
  RETURNING: 'bg-orange-100 text-orange-800',
  RETURNED: 'bg-red-100 text-red-800',
  CANCELLED: 'bg-gray-100 text-gray-800',
};

const ALL_STATUSES = [
  'PENDING',
  'DISPATCHED',
  'PICKED_UP',
  'IN_TRANSIT',
  'ARRIVED_HUB',
  'OUT_FOR_DELIVERY',
  'DELIVERY_FAILED',
  'DELIVERED',
  'RETURNING',
  'RETURNED',
  'CANCELLED',
];

export default function MockCourierApi({ orders: initialOrders, totalOrders }: Props) {
  const [orders, setOrders] = useState<MockOrder[]>(initialOrders);
  const [loading, setLoading] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const [showCreate, setShowCreate] = useState(false);
  const [selectedOrder, setSelectedOrder] = useState<MockOrder | null>(null);
  const [actionLoading, setActionLoading] = useState<string | null>(null);

  const [createForm, setCreateForm] = useState({
    receiver_name: '',
    receiver_phone: '',
    receiver_address: '',
    receiver_city: '',
    item_name: '',
    item_qty: '1',
    cod_amount: '0',
    weight: '0.5',
  });

  const refreshOrders = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await axios.get('/waybills/mock-courier/api/orders');
      setOrders(data.orders);
    } catch {
      toast.error('Failed to refresh orders');
    } finally {
      setLoading(false);
    }
  }, []);

  async function handleCreate() {
    setActionLoading('create');
    try {
      await axios.post('/waybills/mock-courier/create', createForm);
      toast.success('Mock order created');
      setShowCreate(false);
      setCreateForm({
        receiver_name: '',
        receiver_phone: '',
        receiver_address: '',
        receiver_city: '',
        item_name: '',
        item_qty: '1',
        cod_amount: '0',
        weight: '0.5',
      });
      await refreshOrders();
    } catch {
      toast.error('Failed to create order');
    } finally {
      setActionLoading(null);
    }
  }

  async function handleAdvance(trackingNumber: string) {
    setActionLoading(`advance-${trackingNumber}`);
    try {
      const { data } = await axios.post(`/waybills/mock-courier/${trackingNumber}/advance`);
      setOrders((prev) => prev.map((o) => (o.tracking_number === trackingNumber ? data : o)));
      toast.success(`Status advanced to ${data.status}`);
    } catch {
      toast.error('Failed to advance status');
    } finally {
      setActionLoading(null);
    }
  }

  async function handleSetStatus(trackingNumber: string, status: string) {
    setActionLoading(`status-${trackingNumber}`);
    try {
      const { data } = await axios.post(`/waybills/mock-courier/${trackingNumber}/status`, {
        status,
      });
      setOrders((prev) => prev.map((o) => (o.tracking_number === trackingNumber ? data : o)));
      toast.success(`Status set to ${status}`);
    } catch {
      toast.error('Failed to set status');
    } finally {
      setActionLoading(null);
    }
  }

  async function handleCancel(trackingNumber: string) {
    setActionLoading(`cancel-${trackingNumber}`);
    try {
      await axios.post(`/waybills/mock-courier/${trackingNumber}/cancel`);
      await refreshOrders();
      toast.success('Order cancelled');
    } catch {
      toast.error('Failed to cancel order');
    } finally {
      setActionLoading(null);
    }
  }

  async function handleWebhook(trackingNumber: string) {
    setActionLoading(`webhook-${trackingNumber}`);
    try {
      const { data } = await axios.post(`/waybills/mock-courier/${trackingNumber}/webhook`);
      toast.success('Webhook payload generated — check console');
      console.log('Webhook payload for', trackingNumber, data);
    } catch {
      toast.error('Failed to generate webhook');
    } finally {
      setActionLoading(null);
    }
  }

  async function handleDelete(trackingNumber: string) {
    setActionLoading(`delete-${trackingNumber}`);
    try {
      await axios.delete(`/waybills/mock-courier/${trackingNumber}`);
      await refreshOrders();
      toast.success('Mock order deleted');
    } catch {
      toast.error('Failed to delete order');
    } finally {
      setActionLoading(null);
    }
  }

  async function handleResetAll() {
    setActionLoading('reset-all');
    try {
      await axios.post('/waybills/mock-courier/reset-all');
      await refreshOrders();
      toast.success('All mock orders cleared');
    } catch {
      toast.error('Failed to reset');
    } finally {
      setActionLoading(null);
    }
  }

  const filteredOrders = orders.filter(
    (o) =>
      o.tracking_number.toLowerCase().includes(searchTerm.toLowerCase()) ||
      o.receiver_name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      o.status.toLowerCase().includes(searchTerm.toLowerCase())
  );

  return (
    <AppLayout>
      <Head title="Mock Courier API" />

      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <h1 className="text-xl font-bold font-display tracking-tight flex items-center gap-2">
              <Zap className="h-5 w-5 text-amber-500" />
              Mock Courier API
            </h1>
            <Badge variant="secondary">{totalOrders} orders</Badge>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={refreshOrders} disabled={loading}>
              {loading ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <RefreshCw className="h-4 w-4" />
              )}
              Refresh
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={handleResetAll}
              disabled={actionLoading === 'reset-all'}
            >
              <Trash2 className="h-4 w-4 mr-1" />
              Reset All
            </Button>
            <Dialog open={showCreate} onOpenChange={setShowCreate}>
              <DialogTrigger asChild>
                <Button size="sm">
                  <Plus className="h-4 w-4 mr-1" />
                  Create Order
                </Button>
              </DialogTrigger>
              <DialogContent className="max-w-md">
                <DialogHeader>
                  <DialogTitle>Create Mock Courier Order</DialogTitle>
                  <DialogDescription>
                    Simulate a new courier order for testing the tracking workflow.
                  </DialogDescription>
                </DialogHeader>
                <div className="space-y-3">
                  <div>
                    <Label>Receiver Name *</Label>
                    <Input
                      value={createForm.receiver_name}
                      onChange={(e) =>
                        setCreateForm({ ...createForm, receiver_name: e.target.value })
                      }
                    />
                  </div>
                  <div>
                    <Label>Receiver Phone *</Label>
                    <Input
                      value={createForm.receiver_phone}
                      onChange={(e) =>
                        setCreateForm({ ...createForm, receiver_phone: e.target.value })
                      }
                    />
                  </div>
                  <div>
                    <Label>Receiver Address *</Label>
                    <Input
                      value={createForm.receiver_address}
                      onChange={(e) =>
                        setCreateForm({ ...createForm, receiver_address: e.target.value })
                      }
                    />
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <Label>City</Label>
                      <Input
                        value={createForm.receiver_city}
                        onChange={(e) =>
                          setCreateForm({ ...createForm, receiver_city: e.target.value })
                        }
                      />
                    </div>
                    <div>
                      <Label>Item Name</Label>
                      <Input
                        value={createForm.item_name}
                        onChange={(e) =>
                          setCreateForm({ ...createForm, item_name: e.target.value })
                        }
                      />
                    </div>
                  </div>
                  <div className="grid grid-cols-3 gap-3">
                    <div>
                      <Label>Qty</Label>
                      <Input
                        type="number"
                        value={createForm.item_qty}
                        onChange={(e) => setCreateForm({ ...createForm, item_qty: e.target.value })}
                      />
                    </div>
                    <div>
                      <Label>COD Amount</Label>
                      <Input
                        type="number"
                        value={createForm.cod_amount}
                        onChange={(e) =>
                          setCreateForm({ ...createForm, cod_amount: e.target.value })
                        }
                      />
                    </div>
                    <div>
                      <Label>Weight (kg)</Label>
                      <Input
                        type="number"
                        step="0.1"
                        value={createForm.weight}
                        onChange={(e) => setCreateForm({ ...createForm, weight: e.target.value })}
                      />
                    </div>
                  </div>
                  <Button
                    className="w-full"
                    onClick={handleCreate}
                    disabled={
                      actionLoading === 'create' ||
                      !createForm.receiver_name ||
                      !createForm.receiver_phone ||
                      !createForm.receiver_address
                    }
                  >
                    {actionLoading === 'create' ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                      'Create Order'
                    )}
                  </Button>
                </div>
              </DialogContent>
            </Dialog>
          </div>
        </div>

        {/* Search */}
        <div className="flex items-center gap-2">
          <div className="relative flex-1 max-w-sm">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="Search tracking #, receiver, status..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="pl-9"
            />
          </div>
        </div>

        {/* Orders Table */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Package className="h-5 w-5" />
              Mock Orders
            </CardTitle>
          </CardHeader>
          <CardContent>
            {filteredOrders.length === 0 ? (
              <div className="text-center py-12 text-muted-foreground">
                <Package className="h-12 w-12 mx-auto mb-3 opacity-30" />
                <p>No mock orders yet. Create one to get started.</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b bg-muted/50">
                      <th className="h-10 px-3 text-left text-sm font-medium text-muted-foreground">
                        Tracking #
                      </th>
                      <th className="h-10 px-3 text-left text-sm font-medium text-muted-foreground">
                        Receiver
                      </th>
                      <th className="h-10 px-3 text-left text-sm font-medium text-muted-foreground">
                        Status
                      </th>
                      <th className="h-10 px-3 text-left text-sm font-medium text-muted-foreground">
                        COD
                      </th>
                      <th className="h-10 px-3 text-left text-sm font-medium text-muted-foreground">
                        Created
                      </th>
                      <th className="h-10 px-3 text-right text-sm font-medium text-muted-foreground">
                        Actions
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredOrders.map((order) => (
                      <tr key={order.tracking_number} className="border-b hover:bg-muted/50">
                        <td className="p-3 font-mono text-sm">
                          <button
                            className="text-info hover:underline"
                            onClick={() => setSelectedOrder(order)}
                          >
                            {order.tracking_number}
                          </button>
                        </td>
                        <td className="p-3 text-sm">
                          <div className="font-medium">{order.receiver_name}</div>
                          <div className="text-xs text-muted-foreground">
                            {order.receiver_phone}
                          </div>
                        </td>
                        <td className="p-3">
                          <span
                            className={`inline-block px-2 py-1 rounded text-xs font-medium ${STATUS_COLORS[order.status] || 'bg-gray-100'}`}
                          >
                            {order.status}
                          </span>
                        </td>
                        <td className="p-3 text-sm">
                          ₱{Number(order.cod_amount).toLocaleString()}
                        </td>
                        <td className="p-3 text-xs text-muted-foreground">
                          {new Date(order.created_at).toLocaleString()}
                        </td>
                        <td className="p-3">
                          <div className="flex items-center justify-end gap-1">
                            <Button
                              variant="ghost"
                              size="icon"
                              className="h-8 w-8"
                              onClick={() => setSelectedOrder(order)}
                              title="View details"
                            >
                              <Eye className="h-4 w-4" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="icon"
                              className="h-8 w-8"
                              onClick={() => handleAdvance(order.tracking_number)}
                              disabled={
                                actionLoading === `advance-${order.tracking_number}` ||
                                order.status === 'DELIVERED' ||
                                order.status === 'CANCELLED'
                              }
                              title="Advance status"
                            >
                              {actionLoading === `advance-${order.tracking_number}` ? (
                                <Loader2 className="h-4 w-4 animate-spin" />
                              ) : (
                                <Play className="h-4 w-4" />
                              )}
                            </Button>
                            <Button
                              variant="ghost"
                              size="icon"
                              className="h-8 w-8"
                              onClick={() => handleWebhook(order.tracking_number)}
                              disabled={actionLoading === `webhook-${order.tracking_number}`}
                              title="Simulate webhook"
                            >
                              <Send className="h-4 w-4" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="icon"
                              className="h-8 w-8 text-destructive"
                              onClick={() => handleCancel(order.tracking_number)}
                              disabled={
                                actionLoading === `cancel-${order.tracking_number}` ||
                                order.status === 'CANCELLED' ||
                                order.status === 'DELIVERED'
                              }
                              title="Cancel order"
                            >
                              <XCircle className="h-4 w-4" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="icon"
                              className="h-8 w-8 text-destructive"
                              onClick={() => handleDelete(order.tracking_number)}
                              disabled={actionLoading === `delete-${order.tracking_number}`}
                              title="Delete order"
                            >
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Order Detail Dialog */}
      <Dialog open={!!selectedOrder} onOpenChange={(open) => !open && setSelectedOrder(null)}>
        <DialogContent className="max-w-2xl max-h-[80vh] overflow-y-auto">
          {selectedOrder && (
            <>
              <DialogHeader>
                <DialogTitle className="font-mono">{selectedOrder.tracking_number}</DialogTitle>
                <DialogDescription>
                  View and update the status of this mock courier order.
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-4">
                {/* Status + Set Status */}
                <div className="flex items-center gap-3">
                  <span
                    className={`inline-block px-3 py-1.5 rounded text-sm font-medium ${STATUS_COLORS[selectedOrder.status] || 'bg-gray-100'}`}
                  >
                    {selectedOrder.status}
                  </span>
                  <Select
                    value={selectedOrder.status}
                    onValueChange={(val) => handleSetStatus(selectedOrder.tracking_number, val)}
                  >
                    <SelectTrigger className="w-[200px]">
                      <SelectValue placeholder="Set status..." />
                    </SelectTrigger>
                    <SelectContent>
                      {ALL_STATUSES.map((s) => (
                        <SelectItem key={s} value={s}>
                          {s}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                {/* Order Info */}
                <div className="grid grid-cols-2 gap-4 text-sm">
                  <div>
                    <p className="text-muted-foreground">Receiver</p>
                    <p className="font-medium">{selectedOrder.receiver_name}</p>
                    <p className="text-xs">{selectedOrder.receiver_phone}</p>
                    <p className="text-xs">{selectedOrder.receiver_address}</p>
                    {selectedOrder.receiver_city && (
                      <p className="text-xs">{selectedOrder.receiver_city}</p>
                    )}
                  </div>
                  <div>
                    <p className="text-muted-foreground">Item</p>
                    <p className="font-medium">{selectedOrder.item_name || '-'}</p>
                    <p className="text-xs">Qty: {selectedOrder.item_qty}</p>
                    <p className="text-xs">
                      COD: ₱{Number(selectedOrder.cod_amount).toLocaleString()}
                    </p>
                    <p className="text-xs">Weight: {selectedOrder.weight}kg</p>
                  </div>
                </div>

                {/* Tracking History */}
                <div>
                  <h3 className="font-medium mb-2">Tracking History</h3>
                  <div className="space-y-2">
                    {[...selectedOrder.tracking_history].reverse().map((event, i) => (
                      <div key={i} className="flex items-start gap-3 text-sm">
                        <div
                          className={`w-2 h-2 mt-2 rounded-full ${STATUS_COLORS[event.status]?.replace('bg-', 'bg-').split(' ')[0] || 'bg-gray-400'}`}
                        />
                        <div className="flex-1">
                          <div className="flex items-center justify-between">
                            <span className="font-medium">{event.status}</span>
                            <span className="text-xs text-muted-foreground">
                              {new Date(event.timestamp).toLocaleString()}
                            </span>
                          </div>
                          <p className="text-xs text-muted-foreground">{event.location}</p>
                          {event.description && <p className="text-xs">{event.description}</p>}
                        </div>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Action Buttons */}
                <div className="flex gap-2 pt-2 border-t">
                  <Button
                    size="sm"
                    onClick={() => handleAdvance(selectedOrder.tracking_number)}
                    disabled={
                      actionLoading === `advance-${selectedOrder.tracking_number}` ||
                      selectedOrder.status === 'DELIVERED' ||
                      selectedOrder.status === 'CANCELLED'
                    }
                  >
                    <Play className="h-4 w-4 mr-1" />
                    Advance Status
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => handleWebhook(selectedOrder.tracking_number)}
                    disabled={actionLoading === `webhook-${selectedOrder.tracking_number}`}
                  >
                    <Send className="h-4 w-4 mr-1" />
                    Simulate Webhook
                  </Button>
                </div>
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
