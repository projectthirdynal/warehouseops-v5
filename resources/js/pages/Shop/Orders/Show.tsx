import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
  ArrowLeft,
  FileText,
  Pencil,
  Package,
  User,
  Truck,
  Phone,
  MapPin,
  Send,
  Trash2,
  X,
  CornerDownRight,
  Pin,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { AtSign } from 'lucide-react';

interface OrderRemark {
  id: number;
  type: string;
  visibility: string;
  body: string;
  created_at: string;
  updated_at: string;
  parent_id: number | null;
  mentions?: number[] | null;
  is_pinned?: boolean;
  pinned_at?: string | null;
  pinned_by?: { id: number; name: string } | null;
  user?: { id: number; name: string } | null;
  replies?: OrderRemark[];
}

interface OrderItem {
  id: number;
  product_name: string;
  quantity: number;
  unit_price: string | number;
  discount_amount: string | number;
  line_total: string | number;
  product?: { id: number; name: string; sku: string } | null;
  variant?: { id: number; sku: string; variant_name: string } | null;
}

interface Order {
  id: number;
  order_number: string;
  status: string;
  total_amount: string | number;
  cod_amount: string | number;
  shipping_cost: string | number;
  discount_amount: string | number;
  tax_rate: string | number;
  tax_amount: string | number;
  receiver_name: string;
  receiver_phone: string;
  receiver_address: string;
  city: string | null;
  state: string | null;
  barangay: string | null;
  courier_code: string | null;
  notes: string | null;
  remarks: string | null;
  created_at: string;
  confirmed_at: string | null;
  delivered_at: string | null;
  customer?: {
    id: number;
    name: string;
    phone: string;
    normalized_phone: string;
    risk_level: string;
    is_blacklisted: boolean;
    canonical_address: string | null;
    barangay: string | null;
    city_municipality: string | null;
    province: string | null;
  } | null;
  shop_items: OrderItem[];
  agent?: { id: number; name: string } | null;
  remarks_entries?: OrderRemark[];
}

interface RemarkTemplate {
  id: number;
  name: string;
  body: string;
  type: string;
  visibility: string;
}

interface MentionableUser {
  id: number;
  name: string;
  role: string;
}

interface Props {
  order: Order;
  remarkTemplates?: RemarkTemplate[];
  mentionableUsers?: MentionableUser[];
}

function money(value: string | number) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 2,
  }).format(Number(value));
}

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
  const s = status.toUpperCase();
  if (s === 'DELIVERED') return 'default';
  if (s === 'RETURNED' || s === 'CANCELLED' || s === 'QA_REJECTED') return 'destructive';
  if (s === 'CONFIRMED' || s === 'QA_APPROVED') return 'secondary';
  return 'outline';
}

export default function OrderShow({ order, remarkTemplates = [], mentionableUsers = [] }: Props) {
  const remarkEntries = order.remarks_entries ?? [];
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [replyingTo, setReplyingTo] = useState<number | null>(null);
  const [showMentions, setShowMentions] = useState(false);
  const authUserId = (usePage().props.auth as { user?: { id: number } }).user?.id;
  const authRole = (usePage().props.auth as { user?: { role: string } }).user?.role ?? 'agent';
  const canPin = ['supervisor', 'admin', 'superadmin'].includes(authRole);

  const { data, setData, post, processing, reset } = useForm({
    body: '',
    type: 'agent_note',
    visibility: 'internal',
    parent_id: '' as string | number,
    mentions: [] as number[],
  });

  const editForm = useForm({
    body: '',
    type: 'agent_note',
    visibility: 'internal',
    mentions: [] as number[],
  });

  const replyForm = useForm({
    body: '',
    type: 'agent_note',
    visibility: 'internal',
    mentions: [] as number[],
  });

  const getMentionNames = (ids?: number[] | null) => {
    if (!ids || ids.length === 0) return [];
    return ids
      .map((id) => mentionableUsers.find((u) => u.id === id))
      .filter(Boolean) as MentionableUser[];
  };

  const applyTemplate = (templateId: string) => {
    const tpl = remarkTemplates.find((t) => t.id === Number(templateId));
    if (tpl) {
      setData('body', tpl.body);
      setData('type', tpl.type);
      setData('visibility', tpl.visibility);
    }
  };

  const isEditable = (entry: OrderRemark) => {
    if (!['agent_note', 'follow_up', 'escalation', 'customer_feedback'].includes(entry.type))
      return false;
    if (entry.user?.id !== authUserId) return false;
    return Date.now() - new Date(entry.created_at).getTime() < 24 * 60 * 60 * 1000;
  };

  const startEdit = (entry: OrderRemark) => {
    setEditingId(entry.id);
    editForm.setData('body', entry.body);
    editForm.setData('type', entry.type);
    editForm.setData('visibility', entry.visibility);
    editForm.setData('mentions', entry.mentions ?? []);
  };

  const cancelEdit = () => {
    setEditingId(null);
    editForm.reset();
  };

  const submitEdit = (e: React.FormEvent, orderId: number, remarkId: number) => {
    e.preventDefault();
    editForm.patch(`/shop/orders/${orderId}/remarks/${remarkId}`, {
      onSuccess: () => {
        setEditingId(null);
        editForm.reset();
      },
    });
  };

  const deleteRemark = (orderId: number, remarkId: number) => {
    if (!confirm('Delete this remark? This cannot be undone.')) return;
    router.delete(`/shop/orders/${orderId}/remarks/${remarkId}`);
  };

  const togglePin = (orderId: number, remarkId: number) => {
    router.post(`/shop/orders/${orderId}/remarks/${remarkId}/pin`, {}, { preserveScroll: true });
  };

  const toggleMention = (which: 'add' | 'edit' | 'reply', userId: number) => {
    if (which === 'add') {
      const current = data.mentions as number[];
      setData(
        'mentions',
        current.includes(userId) ? current.filter((id) => id !== userId) : [...current, userId]
      );
    } else if (which === 'edit') {
      const current = editForm.data.mentions as number[];
      editForm.setData(
        'mentions',
        current.includes(userId) ? current.filter((id) => id !== userId) : [...current, userId]
      );
    } else {
      const current = replyForm.data.mentions as number[];
      replyForm.setData(
        'mentions',
        current.includes(userId) ? current.filter((id) => id !== userId) : [...current, userId]
      );
    }
  };

  const submitRemark = (e: React.FormEvent) => {
    e.preventDefault();
    post(`/shop/orders/${order.id}/remarks`, {
      onSuccess: () => {
        reset();
        setShowForm(false);
      },
    });
  };

  const startReply = (entry: OrderRemark) => {
    setReplyingTo(entry.id);
    replyForm.setData('body', '');
    replyForm.setData('type', entry.type);
    replyForm.setData('visibility', entry.visibility);
    replyForm.setData('mentions', []);
  };

  const cancelReply = () => {
    setReplyingTo(null);
    replyForm.reset();
  };

  const submitReply = (e: React.FormEvent, parentId: number) => {
    e.preventDefault();
    replyForm.post(`/shop/orders/${order.id}/remarks`, {
      data: {
        body: replyForm.data.body,
        type: replyForm.data.type,
        visibility: replyForm.data.visibility,
        parent_id: parentId,
        mentions: replyForm.data.mentions,
      },
      onSuccess: () => {
        setReplyingTo(null);
        replyForm.reset();
      },
    });
  };

  return (
    <AppLayout>
      <Head title={`Order ${order.order_number}`} />
      <div className="space-y-4 p-4 md:p-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Link href="/shop/orders" className="text-muted-foreground hover:text-foreground">
              <ArrowLeft className="h-5 w-5" />
            </Link>
            <div>
              <h1 className="text-2xl font-bold">{order.order_number}</h1>
              <p className="text-sm text-muted-foreground">
                Created {new Date(order.created_at).toLocaleString()}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <Badge variant={statusVariant(order.status)} className="text-sm">
              {order.status}
            </Badge>
            <Button asChild variant="outline">
              <Link href={`/shop/orders/${order.id}/edit`}>
                <Pencil className="mr-1 h-4 w-4" />
                Edit
              </Link>
            </Button>
          </div>
        </div>

        <div className="grid gap-4 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <User className="h-4 w-4" />
                Customer
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-1 text-sm">
              <div className="flex items-center gap-2">
                <Phone className="h-3.5 w-3.5 text-muted-foreground" />
                <span>{order.receiver_phone}</span>
              </div>
              {order.customer && (
                <Link
                  href={`/shop/customers/${order.customer.id}`}
                  className="block font-medium text-info hover:underline"
                >
                  {order.customer.name}
                </Link>
              )}
              {!order.customer && <div className="font-medium">{order.receiver_name}</div>}
              {order.customer?.risk_level && order.customer.risk_level !== 'LOW' && (
                <Badge
                  variant="outline"
                  className={
                    'text-xs ' +
                    (order.customer.risk_level === 'HIGH'
                      ? 'border-destructive/30 text-destructive'
                      : 'border-warning/30 text-warning')
                  }
                >
                  {order.customer.risk_level} Risk
                </Badge>
              )}
              {order.customer?.is_blacklisted && (
                <Badge variant="destructive" className="text-xs">
                  Blacklisted
                </Badge>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <MapPin className="h-4 w-4" />
                Delivery Address
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-1 text-sm">
              <p>{order.receiver_address || '—'}</p>
              {(order.barangay || order.city || order.state) && (
                <p className="text-muted-foreground">
                  {[order.barangay, order.city, order.state].filter(Boolean).join(', ')}
                </p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <Package className="h-4 w-4" />
                Items
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              {order.shop_items.map((item) => (
                <div key={item.id} className="flex items-center justify-between text-sm">
                  <div>
                    <span className="font-medium">{item.product_name}</span>
                    <span className="text-muted-foreground"> x{item.quantity}</span>
                  </div>
                  <span className="font-medium">{money(item.line_total)}</span>
                </div>
              ))}
              <div className="border-t pt-2 text-sm">
                <div className="flex justify-between text-muted-foreground">
                  <span>Shipping</span>
                  <span>{money(order.shipping_cost ?? 0)}</span>
                </div>
                {Number(order.discount_amount ?? 0) > 0 && (
                  <div className="flex justify-between text-muted-foreground">
                    <span>Discount</span>
                    <span>−{money(order.discount_amount ?? 0)}</span>
                  </div>
                )}
                {Number(order.tax_amount ?? 0) > 0 && (
                  <div className="flex justify-between text-muted-foreground">
                    <span>Tax ({order.tax_rate}%)</span>
                    <span>{money(order.tax_amount ?? 0)}</span>
                  </div>
                )}
                <div className="flex justify-between font-bold">
                  <span>Total</span>
                  <span>{money(order.total_amount)}</span>
                </div>
                <div className="flex justify-between text-muted-foreground">
                  <span>COD Amount</span>
                  <span>{money(order.cod_amount ?? 0)}</span>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <Truck className="h-4 w-4" />
                Courier & Agent
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-1 text-sm">
              <div>
                <span className="text-muted-foreground">Courier: </span>
                <span className="font-medium">{order.courier_code ?? 'MANUAL'}</span>
              </div>
              <div>
                <span className="text-muted-foreground">Agent: </span>
                <span className="font-medium">{order.agent?.name ?? '—'}</span>
              </div>
              {order.confirmed_at && (
                <div className="text-xs text-muted-foreground">
                  Confirmed: {new Date(order.confirmed_at).toLocaleString()}
                </div>
              )}
              {order.delivered_at && (
                <div className="text-xs text-muted-foreground">
                  Delivered: {new Date(order.delivered_at).toLocaleString()}
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        {(order.remarks || order.notes) && (
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <FileText className="h-4 w-4" />
                Order Remarks
              </CardTitle>
              <CardDescription>Remarks captured during order creation.</CardDescription>
            </CardHeader>
            <CardContent>
              <p className="whitespace-pre-wrap text-sm">{order.remarks || order.notes}</p>
            </CardContent>
          </Card>
        )}

        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <div>
                <CardTitle className="text-base">Remark History</CardTitle>
                <CardDescription>All remark entries with author and timestamp.</CardDescription>
              </div>
              {!showForm && (
                <Button size="sm" variant="outline" onClick={() => setShowForm(true)}>
                  <Send className="mr-1 h-3.5 w-3.5" />
                  Add Remark
                </Button>
              )}
            </div>
          </CardHeader>
          <CardContent className="space-y-3">
            {showForm && (
              <form onSubmit={submitRemark} className="space-y-2 rounded-md border p-3">
                {remarkTemplates.length > 0 && (
                  <Select onValueChange={applyTemplate}>
                    <SelectTrigger className="w-full">
                      <SelectValue placeholder="Use a template (optional)..." />
                    </SelectTrigger>
                    <SelectContent>
                      {remarkTemplates.map((tpl) => (
                        <SelectItem key={tpl.id} value={String(tpl.id)}>
                          {tpl.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
                <div className="flex items-center gap-2">
                  <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                    <SelectTrigger className="w-[160px]">
                      <SelectValue placeholder="Type" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="agent_note">Agent Note</SelectItem>
                      <SelectItem value="follow_up">Follow-up</SelectItem>
                      <SelectItem value="escalation">Escalation</SelectItem>
                      <SelectItem value="customer_feedback">Customer Feedback</SelectItem>
                    </SelectContent>
                  </Select>
                  <Select value={data.visibility} onValueChange={(v) => setData('visibility', v)}>
                    <SelectTrigger className="w-[170px]">
                      <SelectValue placeholder="Visibility" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="internal">Internal Only</SelectItem>
                      <SelectItem value="customer_visible">Customer Visible</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                {mentionableUsers.length > 0 && (
                  <div>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="h-7 px-2 text-xs text-muted-foreground"
                      onClick={() => setShowMentions(!showMentions)}
                    >
                      <AtSign className="mr-1 h-3 w-3" />
                      {showMentions ? 'Hide Mentions' : 'Mention Supervisor'}
                      {data.mentions.length > 0 && ` (${data.mentions.length})`}
                    </Button>
                    {showMentions && (
                      <div className="mt-1 flex flex-wrap gap-3 rounded-md border p-2">
                        {mentionableUsers.map((u) => (
                          <label key={u.id} className="flex items-center gap-1.5 text-xs">
                            <Checkbox
                              checked={data.mentions.includes(u.id)}
                              onCheckedChange={() => toggleMention('add', u.id)}
                            />
                            <span>{u.name}</span>
                            <span className="text-muted-foreground">({u.role})</span>
                          </label>
                        ))}
                      </div>
                    )}
                  </div>
                )}
                <Textarea
                  value={data.body}
                  onChange={(e) => setData('body', e.target.value)}
                  placeholder="Enter remark text..."
                  rows={3}
                  required
                />
                <div className="flex justify-end gap-2">
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                      setShowForm(false);
                      reset();
                    }}
                  >
                    Cancel
                  </Button>
                  <Button type="submit" size="sm" disabled={processing || !data.body.trim()}>
                    <Send className="mr-1 h-3.5 w-3.5" />
                    Submit
                  </Button>
                </div>
              </form>
            )}

            {remarkEntries.length === 0 && !showForm && (
              <p className="py-4 text-center text-sm text-muted-foreground">
                No remark entries yet. Click "Add Remark" to create one.
              </p>
            )}

            {remarkEntries.map((entry) => {
              const editable = isEditable(entry);
              const isEditing = editingId === entry.id;
              const isReplying = replyingTo === entry.id;
              const isEdited = entry.updated_at !== entry.created_at;
              const replies = entry.replies ?? [];

              return (
                <div key={entry.id} className="space-y-2">
                  <div
                    className={`rounded-md border p-3 text-sm ${entry.is_pinned ? 'border-primary/40 bg-primary/5' : ''}`}
                  >
                    <div className="mb-1 flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <Badge variant="outline" className="text-xs">
                          {entry.type}
                        </Badge>
                        <Badge
                          variant="outline"
                          className={
                            'text-xs ' +
                            (entry.visibility === 'customer_visible'
                              ? 'border-info/30 text-info'
                              : 'border-muted-foreground/20 text-muted-foreground')
                          }
                        >
                          {entry.visibility === 'customer_visible'
                            ? 'Customer Visible'
                            : 'Internal'}
                        </Badge>
                        <span className="text-xs font-medium">{entry.user?.name ?? 'System'}</span>
                        {isEdited && (
                          <span className="text-xs italic text-muted-foreground">(edited)</span>
                        )}
                        {entry.is_pinned && (
                          <Badge
                            variant="outline"
                            className="border-primary/40 text-xs text-primary"
                          >
                            <Pin className="mr-0.5 h-2.5 w-2.5 fill-current" />
                            Pinned{entry.pinned_by ? ` by ${entry.pinned_by.name}` : ''}
                          </Badge>
                        )}
                        {getMentionNames(entry.mentions).map((m) => (
                          <Badge
                            key={m.id}
                            variant="outline"
                            className="border-amber-400/40 text-xs text-amber-600"
                          >
                            <AtSign className="mr-0.5 h-2.5 w-2.5" />
                            {m.name}
                          </Badge>
                        ))}
                      </div>
                      <div className="flex items-center gap-2">
                        <span className="text-xs text-muted-foreground">
                          {new Date(entry.created_at).toLocaleString()}
                        </span>
                        {editable && !isEditing && (
                          <div className="flex items-center gap-1">
                            <Button
                              size="icon"
                              variant="ghost"
                              className="h-6 w-6"
                              onClick={() => startEdit(entry)}
                            >
                              <Pencil className="h-3 w-3" />
                            </Button>
                            <Button
                              size="icon"
                              variant="ghost"
                              className="h-6 w-6 text-destructive hover:text-destructive"
                              onClick={() => deleteRemark(order.id, entry.id)}
                            >
                              <Trash2 className="h-3 w-3" />
                            </Button>
                          </div>
                        )}
                        {canPin && !isEditing && (
                          <Button
                            size="icon"
                            variant="ghost"
                            className="h-6 w-6"
                            onClick={() => togglePin(order.id, entry.id)}
                            title={entry.is_pinned ? 'Unpin remark' : 'Pin remark to top'}
                          >
                            <Pin
                              className={`h-3 w-3 ${entry.is_pinned ? 'fill-current text-primary' : ''}`}
                            />
                          </Button>
                        )}
                      </div>
                    </div>

                    {isEditing ? (
                      <form
                        onSubmit={(e) => submitEdit(e, order.id, entry.id)}
                        className="space-y-2"
                      >
                        <div className="flex items-center gap-2">
                          <Select
                            value={editForm.data.type}
                            onValueChange={(v) => editForm.setData('type', v)}
                          >
                            <SelectTrigger className="w-[160px]">
                              <SelectValue placeholder="Type" />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="agent_note">Agent Note</SelectItem>
                              <SelectItem value="follow_up">Follow-up</SelectItem>
                              <SelectItem value="escalation">Escalation</SelectItem>
                              <SelectItem value="customer_feedback">Customer Feedback</SelectItem>
                            </SelectContent>
                          </Select>
                          <Select
                            value={editForm.data.visibility}
                            onValueChange={(v) => editForm.setData('visibility', v)}
                          >
                            <SelectTrigger className="w-[170px]">
                              <SelectValue placeholder="Visibility" />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="internal">Internal Only</SelectItem>
                              <SelectItem value="customer_visible">Customer Visible</SelectItem>
                            </SelectContent>
                          </Select>
                        </div>
                        {mentionableUsers.length > 0 && (
                          <div className="flex flex-wrap gap-3 rounded-md border p-2">
                            {mentionableUsers.map((u) => (
                              <label key={u.id} className="flex items-center gap-1.5 text-xs">
                                <Checkbox
                                  checked={editForm.data.mentions.includes(u.id)}
                                  onCheckedChange={() => toggleMention('edit', u.id)}
                                />
                                <span>{u.name}</span>
                                <span className="text-muted-foreground">({u.role})</span>
                              </label>
                            ))}
                          </div>
                        )}
                        <Textarea
                          value={editForm.data.body}
                          onChange={(e) => editForm.setData('body', e.target.value)}
                          rows={3}
                          required
                        />
                        <div className="flex justify-end gap-2">
                          <Button type="button" variant="ghost" size="sm" onClick={cancelEdit}>
                            <X className="mr-1 h-3 w-3" />
                            Cancel
                          </Button>
                          <Button
                            type="submit"
                            size="sm"
                            disabled={editForm.processing || !editForm.data.body.trim()}
                          >
                            <Pencil className="mr-1 h-3 w-3" />
                            Save
                          </Button>
                        </div>
                      </form>
                    ) : (
                      <p className="whitespace-pre-wrap">{entry.body}</p>
                    )}

                    {!isEditing && !isReplying && (
                      <div className="mt-1">
                        <Button
                          size="sm"
                          variant="ghost"
                          className="h-6 px-2 text-xs text-muted-foreground"
                          onClick={() => startReply(entry)}
                        >
                          <CornerDownRight className="mr-1 h-3 w-3" />
                          Reply
                        </Button>
                      </div>
                    )}

                    {isReplying && (
                      <form
                        onSubmit={(e) => submitReply(e, entry.id)}
                        className="mt-2 space-y-2 rounded-md border bg-muted/30 p-2"
                      >
                        <Textarea
                          value={replyForm.data.body}
                          onChange={(e) => replyForm.setData('body', e.target.value)}
                          placeholder="Write a reply..."
                          rows={2}
                          required
                        />
                        <div className="flex items-center justify-between">
                          <div className="flex items-center gap-2">
                            <Select
                              value={replyForm.data.visibility}
                              onValueChange={(v) => replyForm.setData('visibility', v)}
                            >
                              <SelectTrigger className="h-7 w-[150px] text-xs">
                                <SelectValue placeholder="Visibility" />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="internal">Internal Only</SelectItem>
                                <SelectItem value="customer_visible">Customer Visible</SelectItem>
                              </SelectContent>
                            </Select>
                            {mentionableUsers.length > 0 && (
                              <div className="flex flex-wrap gap-2">
                                {mentionableUsers.map((u) => (
                                  <label key={u.id} className="flex items-center gap-1 text-xs">
                                    <Checkbox
                                      checked={replyForm.data.mentions.includes(u.id)}
                                      onCheckedChange={() => toggleMention('reply', u.id)}
                                    />
                                    <span>{u.name}</span>
                                  </label>
                                ))}
                              </div>
                            )}
                          </div>
                          <div className="flex gap-2">
                            <Button type="button" variant="ghost" size="sm" onClick={cancelReply}>
                              <X className="mr-1 h-3 w-3" />
                              Cancel
                            </Button>
                            <Button
                              type="submit"
                              size="sm"
                              disabled={replyForm.processing || !replyForm.data.body.trim()}
                            >
                              <Send className="mr-1 h-3 w-3" />
                              Reply
                            </Button>
                          </div>
                        </div>
                      </form>
                    )}
                  </div>

                  {replies.length > 0 && (
                    <div className="ml-4 space-y-2 border-l-2 border-muted pl-3">
                      {replies.map((reply) => {
                        const replyEditable = isEditable(reply);
                        const replyEditing = editingId === reply.id;
                        const replyEdited = reply.updated_at !== reply.created_at;

                        return (
                          <div key={reply.id} className="rounded-md border p-2 text-sm">
                            <div className="mb-1 flex items-center justify-between">
                              <div className="flex items-center gap-2">
                                <CornerDownRight className="h-3 w-3 text-muted-foreground" />
                                <Badge variant="outline" className="text-xs">
                                  {reply.type}
                                </Badge>
                                <Badge
                                  variant="outline"
                                  className={
                                    'text-xs ' +
                                    (reply.visibility === 'customer_visible'
                                      ? 'border-info/30 text-info'
                                      : 'border-muted-foreground/20 text-muted-foreground')
                                  }
                                >
                                  {reply.visibility === 'customer_visible'
                                    ? 'Customer Visible'
                                    : 'Internal'}
                                </Badge>
                                <span className="text-xs font-medium">
                                  {reply.user?.name ?? 'System'}
                                </span>
                                {replyEdited && (
                                  <span className="text-xs italic text-muted-foreground">
                                    (edited)
                                  </span>
                                )}
                                {getMentionNames(reply.mentions).map((m) => (
                                  <Badge
                                    key={m.id}
                                    variant="outline"
                                    className="border-amber-400/40 text-xs text-amber-600"
                                  >
                                    <AtSign className="mr-0.5 h-2.5 w-2.5" />
                                    {m.name}
                                  </Badge>
                                ))}
                              </div>
                              <div className="flex items-center gap-2">
                                <span className="text-xs text-muted-foreground">
                                  {new Date(reply.created_at).toLocaleString()}
                                </span>
                                {replyEditable && !replyEditing && (
                                  <div className="flex items-center gap-1">
                                    <Button
                                      size="icon"
                                      variant="ghost"
                                      className="h-5 w-5"
                                      onClick={() => startEdit(reply)}
                                    >
                                      <Pencil className="h-3 w-3" />
                                    </Button>
                                    <Button
                                      size="icon"
                                      variant="ghost"
                                      className="h-5 w-5 text-destructive hover:text-destructive"
                                      onClick={() => deleteRemark(order.id, reply.id)}
                                    >
                                      <Trash2 className="h-3 w-3" />
                                    </Button>
                                  </div>
                                )}
                              </div>
                            </div>

                            {replyEditing ? (
                              <form
                                onSubmit={(e) => submitEdit(e, order.id, reply.id)}
                                className="space-y-2"
                              >
                                <Textarea
                                  value={editForm.data.body}
                                  onChange={(e) => editForm.setData('body', e.target.value)}
                                  rows={2}
                                  required
                                />
                                <div className="flex justify-end gap-2">
                                  <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={cancelEdit}
                                  >
                                    Cancel
                                  </Button>
                                  <Button
                                    type="submit"
                                    size="sm"
                                    disabled={editForm.processing || !editForm.data.body.trim()}
                                  >
                                    Save
                                  </Button>
                                </div>
                              </form>
                            ) : (
                              <p className="whitespace-pre-wrap">{reply.body}</p>
                            )}
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>
              );
            })}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
