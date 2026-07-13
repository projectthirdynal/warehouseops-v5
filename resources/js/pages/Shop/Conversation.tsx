import { FormEvent, useEffect, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import {
  AlertCircle,
  ArrowLeft,
  CheckCircle2,
  Clock,
  File as FileIcon,
  History,
  ImageIcon,
  MapPin,
  PackageCheck,
  Plus,
  Send,
  ShoppingCart,
  Trash2,
  User,
  UserCheck,
  Video as VideoIcon,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

interface Attachment {
  type: string;
  payload: {
    url?: string;
    sticker_id?: number;
  } | null;
}

interface Message {
  id: number;
  direction: 'inbound' | 'outbound';
  body: string | null;
  message_type?: string;
  attachments?: Attachment[] | null;
  metadata?: {
    quick_reply_payload?: string;
    quick_replies?: { title: string; payload: string }[];
  } | null;
  sent_at: string | null;
  phone_candidates?: string[] | null;
  raw_payload?: Record<string, unknown> | null;
}

interface Conversation {
  id: number;
  status: string;
  priority: string;
  is_flagged: boolean;
  flag_reason: string | null;
  snoozed_until: string | null;
  snooze_reason: string | null;
  reminder_at: string | null;
  merged_into_id: number | null;
  sentiment: string;
  sentiment_score: number;
  tags: { id: number; name: string; color: string }[];
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
  identity?: {
    id: number;
    display_name?: string | null;
    provider_user_id: string;
    phone_detected?: string | null;
  } | null;
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
  saved_templates: {
    id: number;
    name: string;
    category?: string | null;
    body: string;
    variables?: string[];
  }[];
  agents: { id: number; name: string; role: string }[];
  statuses: string[];
  priorities: string[];
  tags: { id: number; name: string; color: string }[];
  merge_candidates: {
    id: number;
    customer_id: number | null;
    customer_identity_id: number | null;
    last_message_preview: string | null;
    status: string;
    last_message_at: string | null;
    customer?: { name: string } | null;
    identity?: { display_name: string | null } | null;
  }[];
}

function time(value: string | null) {
  if (!value) return '';
  return new Date(value).toLocaleString();
}

function deliveryStatus(message: Message) {
  if (message.direction !== 'outbound') return null;

  const status =
    typeof message.raw_payload?.status === 'string' ? message.raw_payload.status : 'unknown';
  const error = typeof message.raw_payload?.error === 'string' ? message.raw_payload.error : null;

  if (status === 'sent') {
    return { label: 'Sent to Meta', detail: null, className: 'text-success', icon: CheckCircle2 };
  }

  if (status === 'failed') {
    return {
      label: 'Send failed',
      detail: error,
      className: 'text-destructive',
      icon: AlertCircle,
    };
  }

  return {
    label: 'Logged locally',
    detail: status === 'logged' ? null : status,
    className: 'text-muted-foreground',
    icon: Clock,
  };
}

function money(value: string | number | null | undefined) {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(
    Number(value ?? 0)
  );
}

function customerAddress(conversation: Conversation) {
  const customer = conversation.customer;
  if (!customer) return 'No saved address';

  return (
    customer.canonical_address ||
    [customer.barangay, customer.city_municipality, customer.province].filter(Boolean).join(', ') ||
    'No saved address'
  );
}

function label(value: string) {
  return value.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase());
}

export default function ShopConversation({
  conversation,
  recent_orders,
  quick_replies,
  saved_templates,
  agents = [],
  statuses = [],
  priorities = ['low', 'normal', 'high', 'urgent'],
  tags = [],
  merge_candidates = [],
}: Props) {
  const safeMessages = conversation?.messages ?? [];
  const [messages, setMessages] = useState<Message[]>(safeMessages);
  const [lastMessageId, setLastMessageId] = useState<number>(
    safeMessages.reduce((max, m) => (m.id > max ? m.id : max), 0)
  );
  const [pollingEnabled, setPollingEnabled] = useState(true);

  useEffect(() => {
    const propIds = new Set(safeMessages.map((m) => m.id));
    setMessages((prev) => {
      const existingIds = new Set(prev.map((m) => m.id));
      const merged = [...prev];
      // Add any prop messages we don't already have (e.g. after Inertia reload / reply)
      for (const message of safeMessages) {
        if (!existingIds.has(message.id)) {
          merged.push(message);
        }
      }
      // Keep any poll-added messages that aren't in the prop yet
      for (const message of prev) {
        if (!propIds.has(message.id) && !existingIds.has(message.id)) {
          merged.push(message);
        }
      }
      return merged.sort((a, b) => {
        if (a.sent_at && b.sent_at)
          return new Date(a.sent_at).getTime() - new Date(b.sent_at).getTime();
        return a.id - b.id;
      });
    });
    setLastMessageId((prev) => safeMessages.reduce((max, m) => (m.id > max ? m.id : max), prev));
  }, [safeMessages]);

  useEffect(() => {
    if (!pollingEnabled || !conversation?.id) return;

    const interval = setInterval(() => {
      axios
        .get(`/shop/inbox/${conversation.id}/poll`, {
          params: lastMessageId > 0 ? { after_message_id: lastMessageId } : {},
        })
        .then(({ data }) => {
          if (data.messages?.length > 0) {
            setMessages((prev) => [...prev, ...data.messages]);
            const maxId = data.messages.reduce(
              (max: number, m: Message) => (m.id > max ? m.id : max),
              lastMessageId
            );
            setLastMessageId(maxId);
          }
        })
        .catch(() => {
          // Silently ignore poll errors to avoid disrupting the agent
        });
    }, 5000);

    return () => clearInterval(interval);
  }, [conversation?.id, lastMessageId, pollingEnabled]);

  const { data, setData, post, processing, reset, errors } = useForm<{
    body: string;
    quick_replies: { title: string; payload: string }[];
  }>({
    body: '',
    quick_replies: [],
  });
  const [showQuickReplies, setShowQuickReplies] = useState(false);
  const [qrTitle, setQrTitle] = useState('');
  const [qrPayload, setQrPayload] = useState('');
  const customerForm = useForm({
    name: conversation.customer?.name ?? conversation.identity?.display_name ?? '',
    phone:
      conversation.customer?.normalized_phone ??
      conversation.customer?.phone ??
      conversation.identity?.phone_detected ??
      '',
    canonical_address: conversation.customer?.canonical_address ?? '',
    landmark: conversation.customer?.landmark ?? '',
    barangay: conversation.customer?.barangay ?? '',
    city_municipality: conversation.customer?.city_municipality ?? '',
    province: conversation.customer?.province ?? '',
  });

  const updateAssignment = (assignedAgentId: string) => {
    router.patch(
      `/shop/inbox/${conversation.id}/assignment`,
      {
        assigned_agent_id: assignedAgentId ? Number(assignedAgentId) : null,
      },
      { preserveScroll: true }
    );
  };

  const updatePriority = (priority: string) => {
    router.patch(`/shop/inbox/${conversation.id}/priority`, { priority }, { preserveScroll: true });
  };

  const toggleFlag = () => {
    router.patch(
      `/shop/inbox/${conversation.id}/priority`,
      {
        priority: conversation.priority,
        is_flagged: !conversation.is_flagged,
      },
      { preserveScroll: true }
    );
  };

  const [selectedTagIds, setSelectedTagIds] = useState<number[]>(
    (conversation.tags ?? []).map((tag) => tag.id)
  );
  const [newTagName, setNewTagName] = useState('');

  const toggleTag = (tagId: number) => {
    const nextIds = selectedTagIds.includes(tagId)
      ? selectedTagIds.filter((id) => id !== tagId)
      : [...selectedTagIds, tagId];
    setSelectedTagIds(nextIds);
    router.patch(
      `/shop/inbox/${conversation.id}/tags`,
      { tags: nextIds },
      { preserveScroll: true }
    );
  };

  const createTag = (event: FormEvent) => {
    event.preventDefault();
    if (!newTagName.trim()) return;
    router.post(
      '/shop/conversation-tags',
      { name: newTagName.trim() },
      {
        preserveScroll: true,
        onSuccess: () => setNewTagName(''),
      }
    );
  };

  const [snoozeUntil, setSnoozeUntil] = useState('');
  const [snoozeReason, setSnoozeReason] = useState('');
  const [reminderAt, setReminderAt] = useState('');

  const submitSnooze = (event: FormEvent) => {
    event.preventDefault();
    if (!snoozeUntil) return;
    router.post(
      `/shop/inbox/${conversation.id}/snooze`,
      { snoozed_until: snoozeUntil, snooze_reason: snoozeReason || undefined },
      {
        preserveScroll: true,
        onSuccess: () => {
          setSnoozeUntil('');
          setSnoozeReason('');
        },
      }
    );
  };

  const unsnooze = () => {
    router.delete(`/shop/inbox/${conversation.id}/snooze`, { preserveScroll: true });
  };

  const submitReminder = (event: FormEvent) => {
    event.preventDefault();
    if (!reminderAt) return;
    router.post(
      `/shop/inbox/${conversation.id}/reminder`,
      { reminder_at: reminderAt },
      {
        preserveScroll: true,
        onSuccess: () => setReminderAt(''),
      }
    );
  };

  const clearReminder = () => {
    router.delete(`/shop/inbox/${conversation.id}/reminder`, { preserveScroll: true });
  };

  const [mergeSourceId, setMergeSourceId] = useState<number | ''>('');

  const submitMerge = (event: FormEvent) => {
    event.preventDefault();
    if (!mergeSourceId) return;
    router.post(
      `/shop/inbox/${conversation.id}/merge`,
      { source_conversation_id: mergeSourceId },
      {
        preserveScroll: true,
        onSuccess: () => setMergeSourceId(''),
      }
    );
  };

  const updateStatus = (status: string) => {
    router.patch(`/shop/inbox/${conversation.id}/status`, { status }, { preserveScroll: true });
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post(`/shop/inbox/${conversation.id}/reply`, {
      preserveScroll: true,
      onSuccess: () => {
        reset('body', 'quick_replies');
        setShowQuickReplies(false);
      },
    });
  };

  const addQuickReply = () => {
    if (!qrTitle.trim() || !qrPayload.trim()) return;
    setData('quick_replies', [
      ...data.quick_replies,
      { title: qrTitle.trim().slice(0, 20), payload: qrPayload.trim() },
    ]);
    setQrTitle('');
    setQrPayload('');
  };

  const removeQuickReply = (index: number) => {
    setData(
      'quick_replies',
      data.quick_replies.filter((_, i) => i !== index)
    );
  };

  const updateCustomer = (event: FormEvent) => {
    event.preventDefault();
    if (!conversation.customer) return;

    customerForm.patch(`/shop/customers/${conversation.customer.id}`, { preserveScroll: true });
  };

  return (
    <AppLayout>
      <Head title="Shop Conversation" />

      <div className="space-y-4">
        <div>
          <Button asChild variant="ghost" size="sm" className="-ml-3 mb-2">
            <Link href="/shop/inbox">
              <ArrowLeft className="mr-1.5 h-4 w-4" />
              Inbox
            </Link>
          </Button>
          <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
              <h1 className="text-xl font-bold tracking-tight font-display">
                {conversation.customer?.name ??
                  conversation.identity?.display_name ??
                  'Facebook Customer'}
              </h1>
              <p className="text-muted-foreground">
                {conversation.customer?.normalized_phone ??
                  conversation.identity?.phone_detected ??
                  conversation.identity?.provider_user_id}
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button asChild>
                <Link href={`/shop/orders/create?conversation_id=${conversation.id}`}>
                  <ShoppingCart className="mr-1.5 h-4 w-4" />
                  Create Order
                </Link>
              </Button>
              <Badge variant="outline">{conversation.status}</Badge>
              {(conversation.priority ?? 'normal') !== 'normal' && (
                <Badge
                  variant={
                    (conversation.priority ?? 'normal') === 'urgent'
                      ? 'destructive'
                      : (conversation.priority ?? 'normal') === 'high'
                        ? 'default'
                        : 'secondary'
                  }
                >
                  {conversation.priority ?? 'normal'}
                </Badge>
              )}
              {conversation.is_flagged && <Badge variant="destructive">Flagged</Badge>}
              {conversation.facebook_page && <Badge>{conversation.facebook_page.page_name}</Badge>}
            </div>
          </div>
        </div>

        <div className="grid gap-6 xl:grid-cols-3">
          <Card className="xl:col-span-2">
            <CardHeader>
              <div className="flex items-start justify-between gap-3">
                <div>
                  <CardTitle>Messages</CardTitle>
                  <CardDescription>
                    Inbound Meta messages and locally logged replies
                  </CardDescription>
                </div>
                <div className="flex items-center gap-2">
                  <Button
                    type="button"
                    size="sm"
                    variant={pollingEnabled ? 'default' : 'outline'}
                    onClick={() => setPollingEnabled((enabled) => !enabled)}
                    title={pollingEnabled ? 'Polling every 5s' : 'Polling paused'}
                  >
                    {pollingEnabled ? 'Live' : 'Paused'}
                  </Button>
                  <Button asChild size="sm" variant="outline">
                    <Link href="/shop/templates">Templates</Link>
                  </Button>
                </div>
              </div>
            </CardHeader>
            <CardContent className="space-y-4">
              {quick_replies.length > 0 && (
                <div className="space-y-2">
                  <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    Context Suggestions
                  </p>
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
                </div>
              )}

              {saved_templates.length > 0 && (
                <div className="space-y-2">
                  <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    Saved Templates
                  </p>
                  <div className="flex flex-wrap gap-2">
                    {saved_templates.map((template) => (
                      <Button
                        key={template.id}
                        type="button"
                        size="sm"
                        variant="secondary"
                        onClick={() => setData('body', template.body)}
                      >
                        {template.name}
                      </Button>
                    ))}
                  </div>
                </div>
              )}

              {messages.length === 0 ? (
                <p className="py-10 text-center text-sm text-muted-foreground">No messages yet.</p>
              ) : (
                messages.map((message) => {
                  const status = deliveryStatus(message);
                  const StatusIcon = status?.icon;

                  return (
                    <div
                      key={message.id}
                      className={`flex ${message.direction === 'outbound' ? 'justify-end' : 'justify-start'}`}
                    >
                      <div
                        className={`max-w-[78%] rounded-lg border px-3 py-2 text-sm ${
                          message.direction === 'outbound'
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted/40'
                        }`}
                      >
                        {message.body && <p>{message.body}</p>}
                        {message.message_type === 'quick_reply' &&
                          message.metadata?.quick_reply_payload && (
                            <p
                              className={`mt-1 text-xs italic ${message.direction === 'outbound' ? 'text-primary-foreground/70' : 'text-muted-foreground'}`}
                            >
                              Quick Reply: {message.metadata.quick_reply_payload}
                            </p>
                          )}
                        {message.attachments && message.attachments.length > 0 && (
                          <div className="mt-1 space-y-2">
                            {message.attachments.map((att, idx) => {
                              const url = att.payload?.url;
                              if (
                                att.type === 'image' ||
                                att.type === 'image/jpeg' ||
                                att.type === 'image/png' ||
                                att.type === 'gif'
                              ) {
                                return url ? (
                                  <img
                                    key={idx}
                                    src={url}
                                    alt="Attachment"
                                    className="max-w-full rounded-md border"
                                    style={{ maxHeight: '240px' }}
                                  />
                                ) : (
                                  <div key={idx} className="flex items-center gap-1 text-xs">
                                    <ImageIcon className="h-3 w-3" /> Image
                                  </div>
                                );
                              }
                              if (att.type === 'audio' || att.type === 'voice') {
                                return url ? (
                                  <audio key={idx} controls src={url} className="w-full" />
                                ) : (
                                  <div key={idx} className="flex items-center gap-1 text-xs">
                                    <AlertCircle className="h-3 w-3" /> Voice message
                                  </div>
                                );
                              }
                              if (att.type === 'video') {
                                return url ? (
                                  <video
                                    key={idx}
                                    controls
                                    src={url}
                                    className="max-w-full rounded-md"
                                    style={{ maxHeight: '240px' }}
                                  />
                                ) : (
                                  <div key={idx} className="flex items-center gap-1 text-xs">
                                    <VideoIcon className="h-3 w-3" /> Video
                                  </div>
                                );
                              }
                              if (att.type === 'file') {
                                return url ? (
                                  <a
                                    key={idx}
                                    href={url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="flex items-center gap-1 text-xs underline"
                                  >
                                    <FileIcon className="h-3 w-3" /> Download file
                                  </a>
                                ) : (
                                  <div key={idx} className="flex items-center gap-1 text-xs">
                                    <FileIcon className="h-3 w-3" /> File attachment
                                  </div>
                                );
                              }
                              return (
                                <div key={idx} className="text-xs text-muted-foreground">
                                  {att.type} attachment
                                </div>
                              );
                            })}
                          </div>
                        )}
                        {!message.body &&
                          (!message.attachments || message.attachments.length === 0) && (
                            <p className="text-muted-foreground italic">Unsupported message</p>
                          )}
                        <p
                          className={`mt-1 text-xs ${message.direction === 'outbound' ? 'text-primary-foreground/70' : 'text-muted-foreground'}`}
                        >
                          {time(message.sent_at)}
                        </p>
                        {status && StatusIcon && (
                          <div
                            className={`mt-2 flex items-start gap-1 text-xs ${status.className}`}
                          >
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

                {data.quick_replies.length > 0 && (
                  <div className="flex flex-wrap gap-2">
                    {data.quick_replies.map((qr, idx) => (
                      <div
                        key={idx}
                        className="flex items-center gap-1 rounded-md border bg-muted/40 px-2 py-1 text-xs"
                      >
                        <span className="font-medium">{qr.title}</span>
                        <button
                          type="button"
                          onClick={() => removeQuickReply(idx)}
                          className="text-muted-foreground hover:text-destructive"
                        >
                          <Trash2 className="h-3 w-3" />
                        </button>
                      </div>
                    ))}
                  </div>
                )}

                {showQuickReplies && (
                  <div className="space-y-2 rounded-md border p-3">
                    <div className="flex items-center justify-between">
                      <p className="text-sm font-medium">Add Quick Reply Button</p>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setShowQuickReplies(false)}
                      >
                        Cancel
                      </Button>
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                      <div className="flex-1 space-y-1">
                        <label className="text-xs text-muted-foreground">
                          Button Title (max 20 chars)
                        </label>
                        <Input
                          value={qrTitle}
                          onChange={(e) => setQrTitle(e.target.value)}
                          placeholder="e.g. Confirm Order"
                          maxLength={20}
                          className="h-9"
                        />
                      </div>
                      <div className="flex-1 space-y-1">
                        <label className="text-xs text-muted-foreground">Payload</label>
                        <Input
                          value={qrPayload}
                          onChange={(e) => setQrPayload(e.target.value)}
                          placeholder="e.g. CONFIRM_ORDER"
                          className="h-9"
                        />
                      </div>
                      <Button
                        type="button"
                        size="sm"
                        onClick={addQuickReply}
                        disabled={
                          !qrTitle.trim() || !qrPayload.trim() || data.quick_replies.length >= 11
                        }
                      >
                        <Plus className="mr-1 h-3 w-3" />
                        Add
                      </Button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                      {data.quick_replies.length}/11 buttons — Facebook shows these as tappable
                      buttons below your message.
                    </p>
                  </div>
                )}

                <div className="flex items-center justify-between">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setShowQuickReplies((v) => !v)}
                  >
                    <Plus className="mr-1.5 h-3 w-3" />
                    Quick Reply Buttons
                  </Button>
                  <Button type="submit" disabled={processing}>
                    <Send className="mr-1.5 h-4 w-4" />
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
                    <option key={agent.id} value={agent.id}>
                      {agent.name} ({agent.role})
                    </option>
                  ))}
                </select>
                <select
                  value={conversation.status}
                  onChange={(event) => updateStatus(event.target.value)}
                  className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  {statuses.map((status) => (
                    <option key={status} value={status}>
                      {label(status)}
                    </option>
                  ))}
                </select>
              </CardContent>
            </Card>

            {conversation.customer && (
              <Card>
                <CardHeader>
                  <CardTitle>Update Customer</CardTitle>
                  <CardDescription>
                    Save corrected details for future phone matching and same-address orders
                  </CardDescription>
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
                        {customerForm.errors.name && (
                          <p className="text-xs text-destructive">{customerForm.errors.name}</p>
                        )}
                      </div>
                      <div className="space-y-2">
                        <Label htmlFor="customer_phone">Phone</Label>
                        <Input
                          id="customer_phone"
                          value={customerForm.data.phone}
                          onChange={(event) => customerForm.setData('phone', event.target.value)}
                        />
                        {customerForm.errors.phone && (
                          <p className="text-xs text-destructive">{customerForm.errors.phone}</p>
                        )}
                      </div>
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="customer_address">Complete address</Label>
                      <Textarea
                        id="customer_address"
                        value={customerForm.data.canonical_address}
                        onChange={(event) =>
                          customerForm.setData('canonical_address', event.target.value)
                        }
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
                          onChange={(event) =>
                            customerForm.setData('city_municipality', event.target.value)
                          }
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
                    <p className="font-medium">
                      {conversation.customer?.name ??
                        conversation.identity?.display_name ??
                        'No matched customer'}
                    </p>
                    {conversation.customer?.is_blacklisted ? (
                      <Badge variant="destructive">Blacklisted</Badge>
                    ) : conversation.customer?.risk_level ? (
                      <Badge variant="outline">{conversation.customer.risk_level}</Badge>
                    ) : null}
                  </div>
                  <p className="text-muted-foreground">
                    {conversation.customer?.normalized_phone ??
                      conversation.identity?.phone_detected ??
                      'No phone detected'}
                  </p>
                  <p className="text-muted-foreground">
                    PSID: {conversation.identity?.provider_user_id ?? 'unknown'}
                  </p>
                </div>

                <div className="grid grid-cols-3 gap-2">
                  <div className="rounded-lg border p-2">
                    <p className="text-xs text-muted-foreground">Orders</p>
                    <p className="font-semibold">
                      {conversation.customer?.total_orders ?? recent_orders.length}
                    </p>
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
                <CardDescription>
                  {conversation.customer?.region ?? 'Region not mapped'}
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-3 text-sm">
                <p>{customerAddress(conversation)}</p>
                {conversation.customer?.landmark && (
                  <p className="text-muted-foreground">
                    Landmark: {conversation.customer.landmark}
                  </p>
                )}
                <div className="grid gap-2">
                  <Button asChild size="sm">
                    <Link href={`/shop/orders/create?conversation_id=${conversation.id}`}>
                      <ShoppingCart className="mr-1.5 h-4 w-4" />
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
                    <Link
                      key={order.id}
                      href={`/orders/${order.id}`}
                      className="block rounded-lg border p-3 transition-colors hover:bg-accent/30"
                    >
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <p className="text-sm font-medium">{order.order_number}</p>
                          <p className="text-xs text-muted-foreground">
                            {order.product?.name ?? 'No product'}
                          </p>
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
                <p className="text-muted-foreground">
                  Webhook: {conversation.facebook_page?.webhook_status ?? 'unknown'}
                </p>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Snooze & Reminder</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                {conversation.snoozed_until && (
                  <div className="rounded-md border border-primary/20 bg-primary/5 p-3 text-sm">
                    <p className="font-medium text-primary">
                      Snoozed until {time(conversation.snoozed_until)}
                    </p>
                    {conversation.snooze_reason && (
                      <p className="mt-1 text-muted-foreground">{conversation.snooze_reason}</p>
                    )}
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      className="mt-2"
                      onClick={unsnooze}
                    >
                      Unsnooze
                    </Button>
                  </div>
                )}
                {!conversation.snoozed_until && (
                  <form onSubmit={submitSnooze} className="space-y-2">
                    <div>
                      <Label htmlFor="snooze_until">Snooze until</Label>
                      <input
                        id="snooze_until"
                        type="datetime-local"
                        value={snoozeUntil}
                        onChange={(e) => setSnoozeUntil(e.target.value)}
                        className="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                      />
                    </div>
                    <div>
                      <Label htmlFor="snooze_reason">Reason (optional)</Label>
                      <input
                        id="snooze_reason"
                        type="text"
                        value={snoozeReason}
                        onChange={(e) => setSnoozeReason(e.target.value)}
                        placeholder="Waiting for customer response..."
                        className="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                      />
                    </div>
                    <Button type="submit" size="sm" disabled={!snoozeUntil}>
                      Snooze
                    </Button>
                  </form>
                )}

                {conversation.reminder_at && (
                  <div className="rounded-md border border-warning/20 bg-warning/5 p-3 text-sm">
                    <p className="font-medium text-warning">
                      Reminder: {time(conversation.reminder_at)}
                    </p>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      className="mt-2"
                      onClick={clearReminder}
                    >
                      Clear Reminder
                    </Button>
                  </div>
                )}
                {!conversation.reminder_at && (
                  <form onSubmit={submitReminder} className="space-y-2">
                    <div>
                      <Label htmlFor="reminder_at">Set reminder</Label>
                      <input
                        id="reminder_at"
                        type="datetime-local"
                        value={reminderAt}
                        onChange={(e) => setReminderAt(e.target.value)}
                        className="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                      />
                    </div>
                    <Button type="submit" size="sm" disabled={!reminderAt}>
                      Set Reminder
                    </Button>
                  </form>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Sentiment</CardTitle>
              </CardHeader>
              <CardContent className="space-y-2">
                <div className="flex items-center gap-2">
                  {conversation.sentiment === 'positive' && (
                    <Badge className="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">
                      Positive
                    </Badge>
                  )}
                  {conversation.sentiment === 'negative' && (
                    <Badge className="bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300">
                      Negative
                    </Badge>
                  )}
                  {conversation.sentiment === 'neutral' && <Badge variant="outline">Neutral</Badge>}
                  <span className="text-sm text-muted-foreground">
                    Score: {Number(conversation.sentiment_score).toFixed(2)}
                  </span>
                </div>
                <p className="text-xs text-muted-foreground">
                  Based on keyword analysis of recent inbound messages.
                </p>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Merge Duplicate</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                {conversation.merged_into_id ? (
                  <p className="text-sm text-muted-foreground">
                    This conversation has been merged into #{conversation.merged_into_id}.
                  </p>
                ) : (
                  <>
                    <p className="text-sm text-muted-foreground">
                      Merge another conversation into this one. All messages and tags will be
                      transferred.
                    </p>
                    <form onSubmit={submitMerge} className="space-y-2">
                      <select
                        value={mergeSourceId}
                        onChange={(e) =>
                          setMergeSourceId(e.target.value ? Number(e.target.value) : '')
                        }
                        className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                      >
                        <option value="">Select conversation to merge...</option>
                        {merge_candidates.map((candidate) => (
                          <option key={candidate.id} value={candidate.id}>
                            #{candidate.id} —{' '}
                            {candidate.customer?.name ??
                              candidate.identity?.display_name ??
                              'Unknown'}{' '}
                            ({candidate.status})
                          </option>
                        ))}
                      </select>
                      <Button type="submit" size="sm" disabled={!mergeSourceId}>
                        Merge into this conversation
                      </Button>
                    </form>
                    {merge_candidates.length === 0 && (
                      <p className="text-sm text-muted-foreground">
                        No duplicate conversations found.
                      </p>
                    )}
                  </>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Tags</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="flex flex-wrap gap-2">
                  {tags.map((tag) => (
                    <Button
                      key={tag.id}
                      type="button"
                      size="sm"
                      variant={selectedTagIds.includes(tag.id) ? 'default' : 'outline'}
                      onClick={() => toggleTag(tag.id)}
                      style={
                        selectedTagIds.includes(tag.id)
                          ? {}
                          : { borderColor: tag.color, color: tag.color }
                      }
                    >
                      {tag.name}
                    </Button>
                  ))}
                  {tags.length === 0 && (
                    <p className="text-sm text-muted-foreground">No tags yet.</p>
                  )}
                </div>
                <form onSubmit={createTag} className="flex gap-2">
                  <input
                    type="text"
                    value={newTagName}
                    onChange={(e) => setNewTagName(e.target.value)}
                    placeholder="New tag name"
                    className="h-10 flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm"
                  />
                  <Button type="submit" size="sm" disabled={!newTagName.trim()}>
                    Add
                  </Button>
                </form>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Priority & Flagging</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <div>
                  <Label htmlFor="priority">Priority</Label>
                  <select
                    id="priority"
                    value={conversation.priority ?? 'normal'}
                    onChange={(e) => updatePriority(e.target.value)}
                    className="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                  >
                    {priorities.map((p) => (
                      <option key={p} value={p}>
                        {label(p)}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="flex items-center gap-2">
                  <Button
                    variant={conversation.is_flagged ? 'destructive' : 'outline'}
                    size="sm"
                    onClick={toggleFlag}
                  >
                    {conversation.is_flagged ? 'Unflag' : 'Flag conversation'}
                  </Button>
                </div>
                {conversation.is_flagged && conversation.flag_reason && (
                  <p className="text-xs text-muted-foreground">
                    Reason: {conversation.flag_reason}
                  </p>
                )}
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
