import { FormEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertCircle, ArrowLeft, CheckCircle2, Clock, History, MapPin, PackageCheck, Send, ShoppingCart, User, UserCheck } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

interface Message {
  id: number;
  direction: 'inbound' | 'outbound';
  body: string | null;
  sent_at: string | null;
  phone_candidates?: string[] | null;
  raw_payload?: Record<string, unknown> | null;
}

interface Conversation {
  id: number;
  status: string;
  assigned_agent?: { id: number; name: string } | null;
  last_message_at: string | null;
  facebook_page?: { id: number; page_name: string; page_id: string; webhook_status: string } | null;
  customer?: {
    id: number;
    name: string;
    phone: string;
    normalized_phone?: string | null;
    canonical_address?: string | null;
    landmark?: string | null;
    barangay?: string | null;
    city_municipality?: string | null;
    province?: string | null;
    region?: string | null;
    last_order_date?: string | null;
    total_orders?: number | null;
    successful_orders?: number | null;
    returned_orders?: number | null;
    success_rate?: string | number | null;
    total_revenue?: string | number | null;
    risk_level?: string | null;
    is_blacklisted?: boolean;
    blacklist_reason?: string | null;
  } | null;
  identity?: { id: number; display_name?: string | null; provider_user_id: string; phone_detected?: string | null } | null;
  messages: Message[];
}

interface RecentOrder {
  id: number;
  order_number: string;
  status: string;
  total_amount: string | number;
  receiver_address: string | null;
  created_at: string;
  product?: { id: number; name: string; sku: string } | null;
}

interface Props {
  conversation: Conversation;
  recent_orders: RecentOrder[];
  quick_replies: { label: string; body: string }[];
  agents: { id: number; name: string; role: string }[];
  statuses: string[];
}

function time(value: string | null) {
  if (!value) return '';
  return new Date(value).toLocaleString();
}

function deliveryStatus(message: Message) {
  if (message.direction !== 'outbound') return null;

  const status = typeof message.raw_payload?.status === 'string' ? message.raw_payload.status : 'unknown';
  const error = typeof message.raw_payload?.error === 'string' ? message.raw_payload.error : null;

  if (status === 'sent') {
    return { label: 'Sent to Meta', detail: null, className: 'text-emerald-600', icon: CheckCircle2 };
  }

  if (status === 'failed') {
    return { label: 'Send failed', detail: error, className: 'text-destructive', icon: AlertCircle };
  }

  return { label: 'Logged locally', detail: status === 'logged' ? null : status, className: 'text-muted-foreground', icon: Clock };
}

function money(value: string | number | null | undefined) {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value ?? 0));
}

function customerAddress(conversation: Conversation) {
  const customer = conversation.customer;
  if (!customer) return 'No saved address';

  return customer.canonical_address
    || [customer.barangay, customer.city_municipality, customer.province].filter(Boolean).join(', ')
    || 'No saved address';
}

function label(value: string) {
  return value.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase());
}

export default function ShopConversation({ conversation, recent_orders, quick_replies, agents, statuses }: Props) {
  const { data, setData, post, processing, reset, errors } = useForm({ body: '' });
  const customerForm = useForm({
    name: conversation.customer?.name ?? conversation.identity?.display_name ?? '',
    phone: conversation.customer?.normalized_phone ?? conversation.customer?.phone ?? conversation.identity?.phone_detected ?? '',
    canonical_address: conversation.customer?.canonical_address ?? '',
    landmark: conversation.customer?.landmark ?? '',
    barangay: conversation.customer?.barangay ?? '',
    city_municipality: conversation.customer?.city_municipality ?? '',
    province: conversation.customer?.province ?? '',
  });

  const updateAssignment = (assignedAgentId: string) => {
    router.patch(`/shop/inbox/${conversation.id}/assignment`, {
      assigned_agent_id: assignedAgentId ? Number(assignedAgentId) : null,
    }, { preserveScroll: true });
  };

  const updateStatus = (status: string) => {
    router.patch(`/shop/inbox/${conversation.id}/status`, { status }, { preserveScroll: true });
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post(`/shop/inbox/${conversation.id}/reply`, {
      preserveScroll: true,
      onSuccess: () => reset('body'),
    });
  };

  const updateCustomer = (event: FormEvent) => {
    event.preventDefault();
    if (!conversation.customer) return;

    customerForm.patch(`/shop/customers/${conversation.customer.id}`, { preserveScroll: true });
  };

  return (
    <AppLayout>
      <Head title="Shop Conversation" />

      <div className="space-y-6">
        <div>
          <Button asChild variant="ghost" size="sm" className="-ml-3 mb-2">
            <Link href="/shop/inbox">
              <ArrowLeft className="mr-2 h-4 w-4" />
              Inbox
            </Link>
          </Button>
          <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
              <h1 className="text-3xl font-bold tracking-tight">
                {conversation.customer?.name ?? conversation.identity?.display_name ?? 'Facebook Customer'}
              </h1>
              <p className="text-muted-foreground">
                {conversation.customer?.normalized_phone ?? conversation.identity?.phone_detected ?? conversation.identity?.provider_user_id}
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button asChild>
                <Link href={`/shop/orders/create?conversation_id=${conversation.id}`}>
                  <ShoppingCart className="mr-2 h-4 w-4" />
                  Create Order
                </Link>
              </Button>
              <Badge variant="outline">{conversation.status}</Badge>
              {conversation.facebook_page && <Badge>{conversation.facebook_page.page_name}</Badge>}
            </div>
          </div>
        </div>

        <div className="grid gap-6 xl:grid-cols-3">
          <Card className="xl:col-span-2">
            <CardHeader>
              <CardTitle>Messages</CardTitle>
              <CardDescription>Inbound Meta messages and locally logged replies</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              {quick_replies.length > 0 && (
                <div className="flex flex-wrap gap-2">
                  {quick_replies.map((reply) => (
                    <Button
                      key={reply.label}
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => setData('body', reply.body)}
                    >
                      {reply.label}
                    </Button>
                  ))}
                </div>
              )}

              {conversation.messages.length === 0 ? (
                <p className="py-10 text-center text-sm text-muted-foreground">No messages yet.</p>
              ) : (
                conversation.messages.map((message) => {
                  const status = deliveryStatus(message);
                  const StatusIcon = status?.icon;

                  return (
                    <div
                      key={message.id}
                      className={`flex ${message.direction === 'outbound' ? 'justify-end' : 'justify-start'}`}
                    >
                      <div
                        className={`max-w-[78%] rounded-lg border px-3 py-2 text-sm ${
                          message.direction === 'outbound' ? 'bg-primary text-primary-foreground' : 'bg-muted/40'
                        }`}
                      >
                        <p>{message.body ?? 'Attachment or unsupported message'}</p>
                        <p className={`mt-1 text-xs ${message.direction === 'outbound' ? 'text-primary-foreground/70' : 'text-muted-foreground'}`}>
                          {time(message.sent_at)}
                        </p>
                        {status && StatusIcon && (
                          <div className={`mt-2 flex items-start gap-1 text-xs ${status.className}`}>
                            <StatusIcon className="mt-0.5 h-3 w-3 shrink-0" />
                            <span>
                              {status.label}
                              {status.detail ? `: ${status.detail}` : ''}
                            </span>
                          </div>
                        )}
                      </div>
                    </div>
                  );
                })
              )}

              <form onSubmit={submit} className="space-y-3 border-t pt-4">
                <Textarea
                  value={data.body}
                  onChange={(event) => setData('body', event.target.value)}
                  placeholder="Type a reply..."
                />
                {errors.body && <p className="text-xs text-destructive">{errors.body}</p>}
                <div className="flex justify-end">
                  <Button type="submit" disabled={processing}>
                    <Send className="mr-2 h-4 w-4" />
                    Send / Log Reply
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>

          <div className="space-y-6">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <UserCheck className="h-5 w-5" />
                  Workflow
                </CardTitle>
                <CardDescription>Owner and sales desk status</CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                <select
                  value={conversation.assigned_agent?.id ?? ''}
                  onChange={(event) => updateAssignment(event.target.value)}
                  className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  <option value="">Unassigned</option>
                  {agents.map((agent) => (
                    <option key={agent.id} value={agent.id}>{agent.name} ({agent.role})</option>
                  ))}
                </select>
                <select
                  value={conversation.status}
                  onChange={(event) => updateStatus(event.target.value)}
                  className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  {statuses.map((status) => (
                    <option key={status} value={status}>{label(status)}</option>
                  ))}
                </select>
              </CardContent>
            </Card>

            {conversation.customer && (
              <Card>
                <CardHeader>
                  <CardTitle>Update Customer</CardTitle>
                  <CardDescription>Save corrected details for future phone matching and same-address orders</CardDescription>
                </CardHeader>
                <CardContent>
                  <form onSubmit={updateCustomer} className="space-y-4">
                    <div className="grid gap-4 md:grid-cols-2">
                      <div className="space-y-2">
                        <Label htmlFor="customer_name">Name</Label>
                        <Input
                          id="customer_name"
                          value={customerForm.data.name}
                          onChange={(event) => customerForm.setData('name', event.target.value)}
                        />
                        {customerForm.errors.name && <p className="text-xs text-destructive">{customerForm.errors.name}</p>}
                      </div>
                      <div className="space-y-2">
                        <Label htmlFor="customer_phone">Phone</Label>
                        <Input
                          id="customer_phone"
                          value={customerForm.data.phone}
                          onChange={(event) => customerForm.setData('phone', event.target.value)}
                        />
                        {customerForm.errors.phone && <p className="text-xs text-destructive">{customerForm.errors.phone}</p>}
                      </div>
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="customer_address">Complete address</Label>
                      <Textarea
                        id="customer_address"
                        value={customerForm.data.canonical_address}
                        onChange={(event) => customerForm.setData('canonical_address', event.target.value)}
                      />
                    </div>
                    <div className="grid gap-4 md:grid-cols-2">
                      <div className="space-y-2">
                        <Label htmlFor="customer_landmark">Landmark</Label>
                        <Input
                          id="customer_landmark"
                          value={customerForm.data.landmark}
                          onChange={(event) => customerForm.setData('landmark', event.target.value)}
                        />
                      </div>
                      <div className="space-y-2">
                        <Label htmlFor="customer_barangay">Barangay</Label>
                        <Input
                          id="customer_barangay"
                          value={customerForm.data.barangay}
                          onChange={(event) => customerForm.setData('barangay', event.target.value)}
                        />
                      </div>
                      <div className="space-y-2">
                        <Label htmlFor="customer_city">City / Municipality</Label>
                        <Input
                          id="customer_city"
                          value={customerForm.data.city_municipality}
                          onChange={(event) => customerForm.setData('city_municipality', event.target.value)}
                        />
                      </div>
                      <div className="space-y-2">
                        <Label htmlFor="customer_province">Province</Label>
                        <Input
                          id="customer_province"
                          value={customerForm.data.province}
                          onChange={(event) => customerForm.setData('province', event.target.value)}
                        />
                      </div>
                    </div>
                    <div className="flex justify-end">
                      <Button type="submit" disabled={customerForm.processing}>
                        Save Customer
                      </Button>
                    </div>
                  </form>
                </CardContent>
              </Card>
            )}

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <User className="h-5 w-5" />
                  Customer Profile
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4 text-sm">
                <div className="space-y-1">
                  <div className="flex items-center justify-between gap-3">
                    <p className="font-medium">{conversation.customer?.name ?? conversation.identity?.display_name ?? 'No matched customer'}</p>
                    {conversation.customer?.is_blacklisted ? (
                      <Badge variant="destructive">Blacklisted</Badge>
                    ) : conversation.customer?.risk_level ? (
                      <Badge variant="outline">{conversation.customer.risk_level}</Badge>
                    ) : null}
                  </div>
                  <p className="text-muted-foreground">
                    {conversation.customer?.normalized_phone ?? conversation.identity?.phone_detected ?? 'No phone detected'}
                  </p>
                  <p className="text-muted-foreground">PSID: {conversation.identity?.provider_user_id ?? 'unknown'}</p>
                </div>

                <div className="grid grid-cols-3 gap-2">
                  <div className="rounded-lg border p-2">
                    <p className="text-xs text-muted-foreground">Orders</p>
                    <p className="font-semibold">{conversation.customer?.total_orders ?? recent_orders.length}</p>
                  </div>
                  <div className="rounded-lg border p-2">
                    <p className="text-xs text-muted-foreground">Success</p>
                    <p className="font-semibold">{conversation.customer?.success_rate ?? 0}%</p>
                  </div>
                  <div className="rounded-lg border p-2">
                    <p className="text-xs text-muted-foreground">Revenue</p>
                    <p className="font-semibold">{money(conversation.customer?.total_revenue)}</p>
                  </div>
                </div>

                {conversation.customer?.blacklist_reason && (
                  <p className="rounded-lg border border-destructive/30 bg-destructive/10 p-2 text-xs text-destructive">
                    {conversation.customer.blacklist_reason}
                  </p>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <MapPin className="h-5 w-5" />
                  Saved Address
                </CardTitle>
                <CardDescription>{conversation.customer?.region ?? 'Region not mapped'}</CardDescription>
              </CardHeader>
              <CardContent className="space-y-3 text-sm">
                <p>{customerAddress(conversation)}</p>
                {conversation.customer?.landmark && <p className="text-muted-foreground">Landmark: {conversation.customer.landmark}</p>}
                <div className="grid gap-2">
                  <Button asChild size="sm">
                    <Link href={`/shop/orders/create?conversation_id=${conversation.id}`}>
                      <ShoppingCart className="mr-2 h-4 w-4" />
                      Use Same Address
                    </Link>
                  </Button>
                  <Button asChild size="sm" variant="outline">
                    <Link href={`/shop/orders/create?conversation_id=${conversation.id}`}>
                      Update Address in Order
                    </Link>
                  </Button>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <History className="h-5 w-5" />
                  Recent Orders
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                {recent_orders.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No previous orders found.</p>
                ) : (
                  recent_orders.map((order) => (
                    <Link key={order.id} href={`/orders/${order.id}`} className="block rounded-lg border p-3 transition-colors hover:bg-accent/30">
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <p className="text-sm font-medium">{order.order_number}</p>
                          <p className="text-xs text-muted-foreground">{order.product?.name ?? 'No product'}</p>
                        </div>
                        <Badge variant="outline">{order.status}</Badge>
                      </div>
                      <div className="mt-2 flex items-center justify-between text-xs text-muted-foreground">
                        <span>{time(order.created_at)}</span>
                        <span>{money(order.total_amount)}</span>
                      </div>
                    </Link>
                  ))
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <PackageCheck className="h-5 w-5" />
                  Page
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-2 text-sm">
                <p>{conversation.facebook_page?.page_name ?? 'Unknown Page'}</p>
                <p className="text-muted-foreground">Webhook: {conversation.facebook_page?.webhook_status ?? 'unknown'}</p>
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
