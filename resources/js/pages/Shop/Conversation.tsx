import { CSSProperties, FormEvent, useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import {
  AlertCircle,
  ArrowLeft,
  ArrowRightLeft,
  Ban,
  CheckCircle2,
  ChevronDown,
  ChevronUp,
  Clock,
  CalendarClock,
  Copy,
  Download,
  File as FileIcon,
  History,
  ImageIcon,
  MapPin,
  MessageSquare,
  PackageCheck,
  Pencil,
  Plus,
  Printer,
  Send,
  Search,
  Flag,
  Languages,
  Megaphone,
  ShoppingCart,
  Trash2,
  User,
  UserCheck,
  Video as VideoIcon,
  X,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

const csrfToken =
  (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';

const axiosWithCsrf = axios.create({
  headers: { 'X-CSRF-TOKEN': csrfToken },
});

interface Attachment {
  type: string;
  payload: {
    url?: string;
    sticker_id?: number;
  } | null;
}

interface Message {
  id: number;
  sent_by?: number | null;
  sender_name?: string | null;
  direction: 'inbound' | 'outbound';
  body: string | null;
  message_type?: string;
  attachments?: Attachment[] | null;
  metadata?: {
    quick_reply_payload?: string;
    quick_replies?: { title: string; payload: string }[];
  } | null;
  reactions?: Record<string, string> | null;
  is_flagged?: boolean;
  flag_reason?: string | null;
  translated_body?: string | null;
  translated_lang?: string | null;
  sent_at: string | null;
  phone_candidates?: string[] | null;
  raw_payload?: Record<string, unknown> | null;
}

interface Conversation {
  id: number;
  status: string;
  priority: string;
  channel: string;
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
  last_message_preview?: string | null;
  created_at?: string | null;
  first_response_at?: string | null;
  resolved_at?: string | null;
  thread_key?: string | null;
  metadata?: Record<string, unknown> | null;
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
  draft_body?: string | null;
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
  messages: Message[];
  has_more_messages: boolean;
  total_message_count: number;
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
  user_role?: string;
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
  scheduled_messages: { id: number; body: string; scheduled_at: string; status: string }[];
  assignment_history: {
    id: number;
    from_agent: string | null;
    to_agent: string | null;
    assigned_by: string | null;
    reason: string;
    created_at: string | null;
  }[];
  status_history?: {
    id: number;
    from_status: string | null;
    to_status: string;
    changed_by: string;
    changed_by_role: string | null;
    created_at: string | null;
  }[];
  sla?: {
    elapsed_minutes: number | null;
    threshold_minutes: number | null;
    remaining_minutes: number | null;
    status: string;
  };
  sla_thresholds?: Record<string, number | null>;
  status_labels?: Record<string, { label: string; color: string | null }>;
  remarks?: { id: number; body: string; user_name: string; created_at: string | null }[];
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

function label(
  value: string,
  customLabels?: Record<string, { label: string; color: string | null }>
): string {
  if (customLabels?.[value]) {
    return customLabels[value].label;
  }
  return value.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase());
}

function statusColor(
  status: string,
  customLabels?: Record<string, { label: string; color: string | null }>
): string | null {
  return customLabels?.[status]?.color ?? null;
}

function statusBadgeClass(status: string, customColor?: string | null) {
  if (customColor) return 'border';
  switch (status) {
    case 'new':
      return 'border-blue-500/30 text-blue-600';
    case 'assigned':
      return 'border-green-500/30 text-green-600';
    case 'awaiting_customer':
      return 'border-amber-500/30 text-amber-600';
    case 'resolved':
      return 'border-purple-500/30 text-purple-600';
    case 'archived':
      return 'border-muted text-muted-foreground';
    default:
      return '';
  }
}

function statusBadgeStyle(customColor?: string | null): CSSProperties | undefined {
  if (!customColor) return undefined;
  return {
    color: customColor,
    borderColor: customColor + '4d',
  };
}

function allowedTransitions(currentStatus: string, role: string): string[] {
  const transitions: Record<string, string[]> = {
    new: ['assigned', 'awaiting_customer', 'resolved', 'archived'],
    assigned: ['awaiting_customer', 'resolved', 'archived', 'new'],
    awaiting_customer: ['assigned', 'resolved', 'archived'],
    resolved: ['assigned', 'awaiting_customer', 'archived'],
    archived: ['resolved', 'assigned'],
  };
  const isSupervisor = ['supervisor', 'admin', 'superadmin'].includes(role);
  const agentAllowed = ['assigned', 'awaiting_customer', 'resolved'];
  const allowed = transitions[currentStatus] ?? [];
  return isSupervisor ? allowed : allowed.filter((s) => agentAllowed.includes(s));
}

function formatSlaMinutes(minutes: number): string {
  if (minutes < 60) return `${minutes}m`;
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

function slaBadgeClass(slaStatus: string) {
  switch (slaStatus) {
    case 'breached':
      return 'border-red-500/40 text-red-600 bg-red-50';
    case 'warning':
      return 'border-amber-500/40 text-amber-600 bg-amber-50';
    case 'ok':
      return 'border-green-500/30 text-green-600 bg-green-50';
    default:
      return '';
  }
}

export default function ShopConversation({
  conversation,
  recent_orders,
  quick_replies,
  saved_templates,
  agents = [],
  user_role: userRole = 'agent',
  statuses = [],
  priorities = ['low', 'normal', 'high', 'urgent'],
  tags = [],
  merge_candidates = [],
  scheduled_messages: initialScheduled = [],
  assignment_history: assignmentHistory = [],
  status_history: statusHistory = [],
  sla: slaData,
  status_labels: statusLabels = {},
  remarks: initialRemarks = [],
  messages: initialMessages = [],
  has_more_messages: initialHasMore = false,
  total_message_count: totalMessages = 0,
}: Props) {
  const safeMessages = initialMessages;
  const [messages, setMessages] = useState<Message[]>(safeMessages);
  const [lastMessageId, setLastMessageId] = useState<number>(
    safeMessages.reduce((max, m) => (m.id > max ? m.id : max), 0)
  );
  const [pollingEnabled, setPollingEnabled] = useState(true);
  const [isTyping, setIsTyping] = useState(false);
  const [hasMore, setHasMore] = useState(initialHasMore);
  const [loadingOlder, setLoadingOlder] = useState(false);
  const [connectionStatus, setConnectionStatus] = useState<
    'connected' | 'reconnecting' | 'offline'
  >('connected');
  const [newMessageCount, setNewMessageCount] = useState(0);
  const messagesEndRef = useRef<HTMLDivElement | null>(null);
  const scrollContainerRef = useRef<HTMLDivElement | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState<Message[] | null>(null);
  const [isSearching, setIsSearching] = useState(false);
  const searchTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const handleSearch = (value: string) => {
    setSearchQuery(value);
    if (searchTimerRef.current) clearTimeout(searchTimerRef.current);
    if (!value.trim()) {
      setSearchResults(null);
      return;
    }
    searchTimerRef.current = setTimeout(() => {
      setIsSearching(true);
      axios
        .get(`/shop/inbox/${conversation.id}/search`, { params: { q: value } })
        .then(({ data }) => setSearchResults(data.messages))
        .catch(() => setSearchResults([]))
        .finally(() => setIsSearching(false));
    }, 300);
  };

  const scrollToMessage = (messageId: number) => {
    setSearchQuery('');
    setSearchResults(null);
    const el = document.getElementById(`message-${messageId}`);
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      el.classList.add('ring-2', 'ring-primary', 'ring-offset-1');
      setTimeout(() => el.classList.remove('ring-2', 'ring-primary', 'ring-offset-1'), 2000);
    }
  };

  const loadOlderMessages = () => {
    if (loadingOlder || messages.length === 0) return;
    setLoadingOlder(true);
    axios
      .get(`/shop/inbox/${conversation.id}/messages`, {
        params: { before_id: messages[0].id },
      })
      .then(({ data }) => {
        if (data.messages?.length > 0) {
          setMessages((prev) => [...data.messages, ...prev]);
        }
        setHasMore(Boolean(data.has_more));
      })
      .catch(() => {})
      .finally(() => setLoadingOlder(false));
  };

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

    let failCount = 0;

    const interval = setInterval(() => {
      axios
        .get(`/shop/inbox/${conversation.id}/poll`, {
          params: lastMessageId > 0 ? { after_message_id: lastMessageId } : {},
        })
        .then(({ data }) => {
          setConnectionStatus('connected');
          failCount = 0;
          if (data.messages?.length > 0) {
            const inboundCount = data.messages.filter(
              (m: Message) => m.direction === 'inbound'
            ).length;
            setMessages((prev) => [...prev, ...data.messages]);
            const maxId = data.messages.reduce(
              (max: number, m: Message) => (m.id > max ? m.id : max),
              lastMessageId
            );
            setLastMessageId(maxId);
            if (inboundCount > 0) {
              setNewMessageCount((c) => c + inboundCount);
            }
          }
          setIsTyping(Boolean(data.is_typing));
        })
        .catch(() => {
          failCount++;
          if (failCount >= 3) {
            setConnectionStatus('offline');
          } else {
            setConnectionStatus('reconnecting');
          }
        });
    }, 5000);

    return () => clearInterval(interval);
  }, [conversation?.id, lastMessageId, pollingEnabled]);

  useEffect(() => {
    const container = scrollContainerRef.current;
    if (!container) return;
    const isNearBottom =
      container.scrollHeight - container.scrollTop - container.clientHeight < 150;
    if (isNearBottom) {
      messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }
  }, [messages]);

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    setNewMessageCount(0);
  };

  const exportConversation = () => {
    const payload = {
      conversation: {
        id: conversation.id,
        channel: conversation.channel,
        status: conversation.status,
        priority: conversation.priority,
        created_at: conversation.created_at,
        last_message_at: conversation.last_message_at,
        first_response_at: conversation.first_response_at,
        resolved_at: conversation.resolved_at,
        sentiment: conversation.sentiment,
        facebook_page: conversation.facebook_page,
        customer: conversation.customer,
        identity: conversation.identity,
        assigned_agent: conversation.assigned_agent,
      },
      messages: messages.map((m) => ({
        id: m.id,
        direction: m.direction,
        body: m.body,
        sent_at: m.sent_at,
        sender_name: m.sender_name,
        translated_body: m.translated_body,
        is_flagged: m.is_flagged,
      })),
      remarks: initialRemarks,
      exported_at: new Date().toISOString(),
    };
    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `conversation-${conversation.id}.json`;
    a.click();
    URL.revokeObjectURL(url);
  };

  useEffect(() => {
    if (!conversation.customer?.id) return;
    axios
      .get(`/shop/customers/${conversation.customer.id}/addresses`)
      .then(({ data }) => {
        setSavedAddresses(data.addresses ?? []);
      })
      .catch(() => {});
  }, [conversation.customer?.id]);

  const { data, setData, post, processing, reset, errors } = useForm<{
    body: string;
    quick_replies: { title: string; payload: string }[];
  }>({
    body: conversation.draft_body ?? '',
    quick_replies: [],
  });
  const [draftSaved, setDraftSaved] = useState(false);
  const draftTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [scheduledMessages, setScheduledMessages] = useState(initialScheduled);
  const [showSchedule, setShowSchedule] = useState(false);
  const [scheduleAt, setScheduleAt] = useState('');
  const [showBroadcast, setShowBroadcast] = useState(false);
  const [broadcastIds, setBroadcastIds] = useState<number[]>([]);
  const [broadcastResult, setBroadcastResult] = useState<{
    sent: number;
    failed: number;
    skipped: number;
  } | null>(null);
  const [broadcastSending, setBroadcastSending] = useState(false);
  const [showQuickReplies, setShowQuickReplies] = useState(false);
  const [qrTitle, setQrTitle] = useState('');
  const [qrPayload, setQrPayload] = useState('');
  const [templateSearch, setTemplateSearch] = useState('');
  const [templateCategory, setTemplateCategory] = useState('');
  const [selectedTemplate, setSelectedTemplate] = useState<{
    id: number;
    name: string;
    body: string;
    variables?: string[];
  } | null>(null);
  const [templateVars, setTemplateVars] = useState<Record<string, string>>({});
  const [showEditForm, setShowEditForm] = useState(false);
  const [copiedField, setCopiedField] = useState<string | null>(null);
  const [lightboxUrl, setLightboxUrl] = useState<string | null>(null);
  const [showBlockModal, setShowBlockModal] = useState(false);
  const [blockReason, setBlockReason] = useState('');
  const [showTransferModal, setShowTransferModal] = useState(false);
  const [transferAgentId, setTransferAgentId] = useState('');
  const [newRemark, setNewRemark] = useState('');
  const [savedAddresses, setSavedAddresses] = useState<
    {
      id: number;
      label: string | null;
      canonical_address: string | null;
      landmark: string | null;
      barangay: string | null;
      city_municipality: string | null;
      province: string | null;
      region: string | null;
      is_default: boolean;
    }[]
  >([]);
  const [selectedAddressId, setSelectedAddressId] = useState('');

  const selectedAddress = savedAddresses.find((a) => a.id === Number(selectedAddressId));
  const createOrderHref = (() => {
    const base = `/shop/orders/create?conversation_id=${conversation.id}`;
    if (!selectedAddress) return base;
    const params = new URLSearchParams();
    params.set('conversation_id', String(conversation.id));
    if (selectedAddress.canonical_address)
      params.set('complete_address', selectedAddress.canonical_address);
    if (selectedAddress.landmark) params.set('landmark', selectedAddress.landmark);
    if (selectedAddress.barangay) params.set('barangay', selectedAddress.barangay);
    if (selectedAddress.city_municipality)
      params.set('city_municipality', selectedAddress.city_municipality);
    if (selectedAddress.province) params.set('province', selectedAddress.province);
    return `/shop/orders/create?${params.toString()}`;
  })();

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
    if (showSchedule && scheduleAt) {
      axiosWithCsrf
        .post(`/shop/inbox/${conversation.id}/schedule`, {
          body: data.body,
          scheduled_at: scheduleAt,
          quick_replies: data.quick_replies.length > 0 ? data.quick_replies : undefined,
        })
        .then(({ data: res }) => {
          setScheduledMessages((prev) => [...prev, res.scheduled_message]);
          reset('body', 'quick_replies');
          setShowSchedule(false);
          setScheduleAt('');
          setShowQuickReplies(false);
        })
        .catch(() => {});
      return;
    }
    post(`/shop/inbox/${conversation.id}/reply`, {
      preserveScroll: true,
      onSuccess: () => {
        reset('body', 'quick_replies');
        setShowQuickReplies(false);
      },
    });
  };

  const cancelScheduledMessage = (id: number) => {
    axiosWithCsrf
      .delete(`/shop/scheduled-messages/${id}`)
      .then(() => {
        setScheduledMessages((prev) => prev.filter((sm) => sm.id !== id));
      })
      .catch(() => {});
  };

  const sendBroadcast = () => {
    if (!data.body.trim() || broadcastIds.length === 0) return;
    setBroadcastSending(true);
    setBroadcastResult(null);
    axiosWithCsrf
      .post('/shop/broadcast', {
        body: data.body,
        conversation_ids: [conversation.id, ...broadcastIds],
      })
      .then(({ data: res }) => {
        setBroadcastResult(res.summary);
        reset('body', 'quick_replies');
        setShowBroadcast(false);
        setBroadcastIds([]);
      })
      .catch(() => {})
      .finally(() => setBroadcastSending(false));
  };

  const toggleBroadcastId = (id: number) => {
    setBroadcastIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
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

  const insertTemplate = (template: {
    id: number;
    name: string;
    body: string;
    variables?: string[];
  }) => {
    if (!template.variables || template.variables.length === 0) {
      setData('body', template.body);
      setSelectedTemplate(null);
      return;
    }
    setSelectedTemplate(template);
    setTemplateVars(template.variables.reduce((acc, v) => ({ ...acc, [v]: '' }), {}));
  };

  const confirmInsertTemplate = () => {
    if (!selectedTemplate) return;
    let body = selectedTemplate.body;
    for (const [key, value] of Object.entries(templateVars)) {
      body = body.split(`{${key}}`).join(value);
    }
    setData('body', body);
    setSelectedTemplate(null);
    setTemplateVars({});
  };

  const riskBadgeClass = (risk?: string | null): string => {
    if (!risk) return '';
    const r = risk.toLowerCase();
    if (r === 'high' || r === 'blacklisted')
      return 'border-destructive/40 text-destructive bg-destructive/10';
    if (r === 'medium')
      return 'border-amber-500/40 text-amber-600 bg-amber-50 dark:bg-amber-950/30';
    if (r === 'low') return 'border-green-500/40 text-green-600 bg-green-50 dark:bg-green-950/30';
    return '';
  };

  const copyToClipboard = (text: string, field: string) => {
    navigator.clipboard.writeText(text).then(() => {
      setCopiedField(field);
      setTimeout(() => setCopiedField(null), 2000);
    });
  };

  const orderStatusBadgeClass = (status: string): string => {
    const s = status.toLowerCase();
    if (s === 'delivered' || s === 'completed' || s === 'successful')
      return 'border-green-500/40 text-green-600 bg-green-50 dark:bg-green-950/30';
    if (s === 'returned' || s === 'cancelled' || s === 'failed')
      return 'border-destructive/40 text-destructive bg-destructive/10';
    if (s === 'pending' || s === 'processing' || s === 'in_transit' || s === 'shipped')
      return 'border-amber-500/40 text-amber-600 bg-amber-50 dark:bg-amber-950/30';
    return '';
  };

  const submitRemark = (e: FormEvent) => {
    e.preventDefault();
    if (!newRemark.trim()) return;
    router.post(
      `/shop/inbox/${conversation.id}/remarks`,
      { body: newRemark },
      {
        preserveScroll: true,
        onSuccess: () => {
          setNewRemark('');
          router.reload({ only: ['remarks'] });
        },
      }
    );
  };

  const deleteRemark = (remarkId: number) => {
    router.delete(`/shop/remarks/${remarkId}`, {
      preserveScroll: true,
      onSuccess: () => {
        router.reload({ only: ['remarks'] });
      },
    });
  };

  type ActivityEntry = {
    id: string;
    type: 'assignment' | 'status' | 'remark' | 'flag' | 'snooze';
    timestamp: string | null;
    description: string;
    actor: string | null;
    badge?: { text: string; className?: string };
  };

  const activityLog: ActivityEntry[] = (() => {
    const entries: ActivityEntry[] = [];

    for (const h of assignmentHistory) {
      entries.push({
        id: `assign-${h.id}`,
        type: 'assignment',
        timestamp: h.created_at,
        description: `${h.from_agent ?? 'Unassigned'} → ${h.to_agent ?? 'Unassigned'}`,
        actor: h.assigned_by,
        badge: { text: h.reason.replace(/_/g, ' ') },
      });
    }

    for (const h of statusHistory) {
      entries.push({
        id: `status-${h.id}`,
        type: 'status',
        timestamp: h.created_at,
        description: `${h.from_status ? label(h.from_status, statusLabels) + ' → ' : ''}${label(h.to_status, statusLabels)}`,
        actor: h.changed_by,
        badge: h.changed_by_role ? { text: h.changed_by_role } : undefined,
      });
    }

    for (const r of initialRemarks) {
      entries.push({
        id: `remark-${r.id}`,
        type: 'remark',
        timestamp: r.created_at,
        description: r.body.length > 80 ? r.body.slice(0, 80) + '…' : r.body,
        actor: r.user_name,
        badge: { text: 'note' },
      });
    }

    if (conversation.is_flagged) {
      entries.push({
        id: 'flag-current',
        type: 'flag',
        timestamp: null,
        description: conversation.flag_reason || 'Conversation flagged',
        actor: null,
        badge: { text: 'flag', className: 'border-destructive/40 text-destructive' },
      });
    }

    if (conversation.snoozed_until) {
      entries.push({
        id: 'snooze-current',
        type: 'snooze',
        timestamp: conversation.snoozed_until,
        description: `Snoozed until ${time(conversation.snoozed_until)}`,
        actor: null,
        badge: { text: 'snooze' },
      });
    }

    entries.sort((a, b) => {
      const ta = a.timestamp ? new Date(a.timestamp).getTime() : 0;
      const tb = b.timestamp ? new Date(b.timestamp).getTime() : 0;
      return tb - ta;
    });

    return entries;
  })();

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
                <Link href={createOrderHref}>
                  <ShoppingCart className="mr-1.5 h-4 w-4" />
                  Create Order
                </Link>
              </Button>
              <Button type="button" variant="outline" onClick={() => setShowTransferModal(true)}>
                <ArrowRightLeft className="mr-1.5 h-4 w-4" />
                Transfer
              </Button>
              <Button type="button" variant="outline" onClick={() => window.print()}>
                <Printer className="mr-1.5 h-4 w-4" />
                Print
              </Button>
              <Button type="button" variant="outline" onClick={exportConversation}>
                <Download className="mr-1.5 h-4 w-4" />
                Export
              </Button>
              {conversation.customer && (
                <Button
                  type="button"
                  variant={conversation.customer.is_blacklisted ? 'default' : 'destructive'}
                  onClick={() => {
                    if (conversation.customer?.is_blacklisted) {
                      router.post(
                        `/shop/inbox/${conversation.id}/block`,
                        { block: false },
                        { preserveScroll: true }
                      );
                    } else {
                      setShowBlockModal(true);
                    }
                  }}
                >
                  <Ban className="mr-1.5 h-4 w-4" />
                  {conversation.customer.is_blacklisted ? 'Unblock' : 'Block'}
                </Button>
              )}
              <Badge
                variant="outline"
                className={statusBadgeClass(
                  conversation.status,
                  statusColor(conversation.status, statusLabels)
                )}
                style={statusBadgeStyle(statusColor(conversation.status, statusLabels))}
              >
                {label(conversation.status, statusLabels)}
              </Badge>
              {slaData && slaData.status !== 'none' && (
                <Badge
                  variant="outline"
                  className={slaBadgeClass(slaData.status)}
                  title={`Elapsed: ${formatSlaMinutes(slaData.elapsed_minutes ?? 0)} / Threshold: ${formatSlaMinutes(slaData.threshold_minutes ?? 0)}`}
                >
                  {slaData.status === 'breached'
                    ? `SLA breached (${formatSlaMinutes(slaData.elapsed_minutes ?? 0)})`
                    : `SLA ${formatSlaMinutes(slaData.remaining_minutes ?? 0)} left`}
                </Badge>
              )}
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
              {conversation.sentiment !== 'neutral' && (
                <Badge
                  variant="outline"
                  className={
                    conversation.sentiment === 'positive'
                      ? 'border-green-500/40 text-green-600 bg-green-50 dark:bg-green-950/30'
                      : 'border-red-500/40 text-red-600 bg-red-50 dark:bg-red-950/30'
                  }
                >
                  {conversation.sentiment === 'positive' ? '😊' : '😟'} {conversation.sentiment}
                </Badge>
              )}
            </div>
          </div>
        </div>

        <div className="grid gap-6 xl:grid-cols-3">
          <Card className="print-conversation xl:col-span-2">
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
                    onClick={() => {
                      setPollingEnabled((enabled) => !enabled);
                      setConnectionStatus('connected');
                    }}
                    title={
                      !pollingEnabled
                        ? 'Polling paused'
                        : connectionStatus === 'offline'
                          ? 'Connection lost — retrying'
                          : connectionStatus === 'reconnecting'
                            ? 'Reconnecting...'
                            : 'Polling every 5s'
                    }
                  >
                    {!pollingEnabled ? (
                      'Paused'
                    ) : (
                      <span className="flex items-center gap-1.5">
                        <span
                          className={`h-2 w-2 rounded-full ${
                            connectionStatus === 'connected'
                              ? 'bg-green-400'
                              : connectionStatus === 'reconnecting'
                                ? 'bg-amber-400 animate-pulse'
                                : 'bg-red-400 animate-pulse'
                          }`}
                        />
                        {connectionStatus === 'offline'
                          ? 'Offline'
                          : connectionStatus === 'reconnecting'
                            ? 'Reconnecting'
                            : 'Live'}
                      </span>
                    )}
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
                  <div className="flex flex-wrap items-center gap-2">
                    <div className="relative flex-1 min-w-[160px]">
                      <Search className="absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                      <Input
                        type="text"
                        value={templateSearch}
                        onChange={(e) => setTemplateSearch(e.target.value)}
                        placeholder="Search templates..."
                        className="h-8 pl-8 text-xs"
                      />
                    </div>
                    {Array.from(
                      new Set(
                        saved_templates
                          .map((t) => t.category)
                          .filter((c): c is string => Boolean(c))
                      )
                    ).map((cat) => (
                      <button
                        key={cat}
                        type="button"
                        onClick={() => setTemplateCategory(templateCategory === cat ? '' : cat)}
                        className={`rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors ${
                          templateCategory === cat
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted text-muted-foreground hover:bg-muted/80'
                        }`}
                      >
                        {cat}
                      </button>
                    ))}
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {saved_templates
                      .filter(
                        (t) =>
                          (!templateSearch ||
                            t.name.toLowerCase().includes(templateSearch.toLowerCase())) &&
                          (!templateCategory || t.category === templateCategory)
                      )
                      .map((template) => (
                        <Button
                          key={template.id}
                          type="button"
                          size="sm"
                          variant={selectedTemplate?.id === template.id ? 'default' : 'secondary'}
                          onClick={() => insertTemplate(template)}
                          className="gap-1.5"
                        >
                          {template.name}
                          {template.variables && template.variables.length > 0 && (
                            <span className="rounded bg-primary-foreground/20 px-1 text-[10px]">
                              {template.variables.length} var
                            </span>
                          )}
                        </Button>
                      ))}
                  </div>
                  {selectedTemplate && (
                    <div className="space-y-2 rounded-md border bg-muted/30 p-3">
                      <div className="flex items-center justify-between">
                        <p className="text-sm font-medium">
                          Fill in variables for: {selectedTemplate.name}
                        </p>
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          onClick={() => {
                            setSelectedTemplate(null);
                            setTemplateVars({});
                          }}
                        >
                          Cancel
                        </Button>
                      </div>
                      {Object.keys(templateVars).map((varName) => (
                        <div key={varName} className="space-y-1">
                          <label className="text-xs text-muted-foreground">
                            {'{' + varName + '}'}
                          </label>
                          <Input
                            value={templateVars[varName]}
                            onChange={(e) =>
                              setTemplateVars((prev) => ({
                                ...prev,
                                [varName]: e.target.value,
                              }))
                            }
                            placeholder={`Enter value for ${varName}`}
                            className="h-8 text-sm"
                          />
                        </div>
                      ))}
                      <div className="rounded-md border bg-background p-2 text-xs text-muted-foreground">
                        <p className="mb-1 font-medium text-foreground">Preview:</p>
                        {(() => {
                          let preview = selectedTemplate.body;
                          for (const [key, value] of Object.entries(templateVars)) {
                            preview = preview.split(`{${key}}`).join(value || `{${key}}`);
                          }
                          return <p className="whitespace-pre-wrap">{preview}</p>;
                        })()}
                      </div>
                      <Button
                        type="button"
                        size="sm"
                        onClick={confirmInsertTemplate}
                        disabled={Object.values(templateVars).some((v) => !v.trim())}
                      >
                        Insert into Reply
                      </Button>
                    </div>
                  )}
                </div>
              )}

              <div className="relative">
                <div className="relative">
                  <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    type="text"
                    value={searchQuery}
                    onChange={(e) => handleSearch(e.target.value)}
                    placeholder="Search messages..."
                    className="pl-9 pr-8"
                  />
                  {searchQuery && (
                    <button
                      type="button"
                      onClick={() => handleSearch('')}
                      className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                    >
                      ✕
                    </button>
                  )}
                </div>
                {searchResults !== null && (
                  <div className="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-md border bg-background shadow-md">
                    {isSearching ? (
                      <p className="px-3 py-2 text-xs text-muted-foreground">Searching...</p>
                    ) : searchResults.length === 0 ? (
                      <p className="px-3 py-2 text-xs text-muted-foreground">No results found.</p>
                    ) : (
                      <ul className="divide-y">
                        {searchResults.map((msg) => (
                          <li key={msg.id}>
                            <button
                              type="button"
                              onClick={() => scrollToMessage(msg.id)}
                              className="w-full px-3 py-2 text-left text-xs hover:bg-muted/50"
                            >
                              <span
                                className={`mr-1.5 font-medium ${
                                  msg.direction === 'outbound'
                                    ? 'text-primary'
                                    : 'text-muted-foreground'
                                }`}
                              >
                                {msg.direction === 'outbound' ? 'Agent' : 'Customer'}
                              </span>
                              <span className="text-muted-foreground">{time(msg.sent_at)}</span>
                              <p className="mt-0.5 line-clamp-2 text-foreground">
                                {msg.body || '[attachment]'}
                              </p>
                            </button>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                )}
              </div>

              <div
                ref={scrollContainerRef}
                className="relative max-h-[60vh] space-y-2 overflow-y-auto py-2"
              >
                {hasMore && (
                  <div className="flex justify-center py-2">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={loadOlderMessages}
                      disabled={loadingOlder}
                    >
                      <ChevronUp className="mr-1.5 h-4 w-4" />
                      {loadingOlder
                        ? 'Loading...'
                        : `Load older messages (${totalMessages - messages.length} more)`}
                    </Button>
                  </div>
                )}

                {messages.length === 0 ? (
                  <p className="py-10 text-center text-sm text-muted-foreground">
                    No messages yet.
                  </p>
                ) : (
                  messages.map((message) => {
                    const status = deliveryStatus(message);
                    const StatusIcon = status?.icon;
                    const senderLabel =
                      message.direction === 'outbound'
                        ? (message.sender_name ?? 'Agent')
                        : (conversation.customer?.name ??
                          conversation.identity?.display_name ??
                          'Customer');
                    const senderInitial = senderLabel.charAt(0).toUpperCase();

                    return (
                      <div
                        key={message.id}
                        id={`message-${message.id}`}
                        className={`flex gap-2 ${message.direction === 'outbound' ? 'justify-end' : 'justify-start'}`}
                      >
                        {message.direction === 'inbound' && (
                          <div className="mt-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium text-muted-foreground">
                            {senderInitial}
                          </div>
                        )}
                        <div className="max-w-[78%]">
                          <p
                            className={`mb-0.5 text-xs font-medium text-muted-foreground ${message.direction === 'outbound' ? 'text-right' : 'text-left'}`}
                          >
                            {senderLabel}
                          </p>
                          <div
                            className={`group rounded-lg border px-3 py-2 text-sm ${
                              message.direction === 'outbound'
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-muted/40'
                            } ${message.is_flagged ? 'border-destructive/60 ring-1 ring-destructive/30' : ''}`}
                          >
                            {message.body && <p>{message.body}</p>}
                            {message.translated_body && (
                              <p
                                className={`mt-1 border-t pt-1 text-xs italic ${
                                  message.direction === 'outbound'
                                    ? 'border-primary-foreground/20 text-primary-foreground/70'
                                    : 'border-muted text-muted-foreground'
                                }`}
                              >
                                {message.translated_body}
                              </p>
                            )}
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
                                  const isImage =
                                    att.type === 'image' ||
                                    att.type === 'image/jpeg' ||
                                    att.type === 'image/png' ||
                                    att.type === 'gif';
                                  const isAudio = att.type === 'audio' || att.type === 'voice';
                                  const isVideo = att.type === 'video';
                                  const isFile = att.type === 'file';

                                  if (isImage) {
                                    return url ? (
                                      <div key={idx} className="group relative inline-block">
                                        <img
                                          src={url}
                                          alt="Attachment"
                                          className="max-w-full cursor-zoom-in rounded-md border transition-opacity hover:opacity-90"
                                          style={{ maxHeight: '240px' }}
                                          onClick={() => setLightboxUrl(url)}
                                        />
                                        <a
                                          href={url}
                                          download
                                          target="_blank"
                                          rel="noopener noreferrer"
                                          className="absolute bottom-1 right-1 rounded-md bg-background/80 p-1 opacity-0 transition-opacity group-hover:opacity-100"
                                          title="Download"
                                        >
                                          <Download className="h-3.5 w-3.5" />
                                        </a>
                                      </div>
                                    ) : (
                                      <div key={idx} className="flex items-center gap-1 text-xs">
                                        <ImageIcon className="h-3 w-3" /> Image
                                      </div>
                                    );
                                  }
                                  if (isAudio) {
                                    return url ? (
                                      <div key={idx} className="space-y-1">
                                        <audio controls src={url} className="w-full" />
                                        <a
                                          href={url}
                                          download
                                          target="_blank"
                                          rel="noopener noreferrer"
                                          className="flex items-center gap-1 text-xs underline"
                                        >
                                          <Download className="h-3 w-3" /> Download audio
                                        </a>
                                      </div>
                                    ) : (
                                      <div key={idx} className="flex items-center gap-1 text-xs">
                                        <AlertCircle className="h-3 w-3" /> Voice message
                                      </div>
                                    );
                                  }
                                  if (isVideo) {
                                    return url ? (
                                      <div key={idx} className="space-y-1">
                                        <video
                                          controls
                                          src={url}
                                          className="max-w-full rounded-md"
                                          style={{ maxHeight: '240px' }}
                                        />
                                        <a
                                          href={url}
                                          download
                                          target="_blank"
                                          rel="noopener noreferrer"
                                          className="flex items-center gap-1 text-xs underline"
                                        >
                                          <Download className="h-3 w-3" /> Download video
                                        </a>
                                      </div>
                                    ) : (
                                      <div key={idx} className="flex items-center gap-1 text-xs">
                                        <VideoIcon className="h-3 w-3" /> Video
                                      </div>
                                    );
                                  }
                                  if (isFile) {
                                    return url ? (
                                      <a
                                        key={idx}
                                        href={url}
                                        download
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="flex items-center gap-2 rounded-md border px-2.5 py-1.5 text-xs transition-colors hover:bg-accent/30"
                                      >
                                        <FileIcon className="h-4 w-4 shrink-0" />
                                        <span className="underline">Download file</span>
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
                            {message.reactions && Object.keys(message.reactions).length > 0 && (
                              <div className="mt-1.5 flex flex-wrap gap-1">
                                {Object.entries(message.reactions).map(([key, emoji]) => (
                                  <span
                                    key={key}
                                    className={`inline-flex items-center rounded-full px-1.5 py-0.5 text-xs ${
                                      message.direction === 'outbound'
                                        ? 'bg-primary-foreground/20'
                                        : 'bg-muted'
                                    }`}
                                  >
                                    {emoji}
                                  </span>
                                ))}
                              </div>
                            )}
                            <div
                              className={`mt-1 flex gap-1 ${message.direction === 'outbound' ? 'justify-end' : 'justify-start'}`}
                            >
                              {['👍', '❤️', '😂', '😮', '😢', '🙏'].map((emoji) => (
                                <button
                                  key={emoji}
                                  type="button"
                                  className="text-sm opacity-0 transition-opacity hover:scale-125 group-hover:opacity-100"
                                  onClick={() => {
                                    axiosWithCsrf
                                      .post(`/shop/messages/${message.id}/reaction`, { emoji })
                                      .then(({ data }) => {
                                        setMessages((prev) =>
                                          prev.map((m) =>
                                            m.id === message.id
                                              ? { ...m, reactions: data.reactions }
                                              : m
                                          )
                                        );
                                      })
                                      .catch(() => {});
                                  }}
                                >
                                  {emoji}
                                </button>
                              ))}
                              <button
                                type="button"
                                className={`ml-1 text-sm opacity-0 transition-opacity hover:scale-125 group-hover:opacity-100 ${
                                  message.is_flagged ? 'text-destructive opacity-100' : ''
                                }`}
                                title={
                                  message.is_flagged
                                    ? `Flagged: ${message.flag_reason}`
                                    : 'Flag message'
                                }
                                onClick={() => {
                                  if (message.is_flagged) {
                                    axiosWithCsrf
                                      .post(`/shop/messages/${message.id}/flag`, {})
                                      .then(({ data }) => {
                                        setMessages((prev) =>
                                          prev.map((m) =>
                                            m.id === message.id
                                              ? {
                                                  ...m,
                                                  is_flagged: data.is_flagged,
                                                  flag_reason: data.flag_reason,
                                                }
                                              : m
                                          )
                                        );
                                      })
                                      .catch(() => {});
                                  } else {
                                    const reason = window.prompt('Flag reason (optional):');
                                    axiosWithCsrf
                                      .post(`/shop/messages/${message.id}/flag`, {
                                        flag_reason: reason || undefined,
                                      })
                                      .then(({ data }) => {
                                        setMessages((prev) =>
                                          prev.map((m) =>
                                            m.id === message.id
                                              ? {
                                                  ...m,
                                                  is_flagged: data.is_flagged,
                                                  flag_reason: data.flag_reason,
                                                }
                                              : m
                                          )
                                        );
                                      })
                                      .catch(() => {});
                                  }
                                }}
                              >
                                <Flag className="h-4 w-4" />
                              </button>
                              {message.body && (
                                <button
                                  type="button"
                                  className="ml-1 text-sm opacity-0 transition-opacity hover:scale-125 group-hover:opacity-100"
                                  title="Translate to English"
                                  onClick={() => {
                                    axiosWithCsrf
                                      .post(`/shop/messages/${message.id}/translate`, {
                                        target_lang: 'en',
                                      })
                                      .then(({ data }) => {
                                        setMessages((prev) =>
                                          prev.map((m) =>
                                            m.id === message.id
                                              ? {
                                                  ...m,
                                                  translated_body: data.translated_body,
                                                  translated_lang: data.translated_lang,
                                                }
                                              : m
                                          )
                                        );
                                      })
                                      .catch(() => {});
                                  }}
                                >
                                  <Languages className="h-4 w-4" />
                                </button>
                              )}
                            </div>
                            {message.is_flagged && (
                              <div className="mt-1 flex items-center gap-1 text-xs text-destructive">
                                <Flag className="h-3 w-3" />
                                <span>{message.flag_reason || 'Flagged'}</span>
                              </div>
                            )}
                          </div>
                        </div>
                        {message.direction === 'outbound' && (
                          <div className="mt-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-medium text-primary">
                            {senderInitial}
                          </div>
                        )}
                      </div>
                    );
                  })
                )}

                {isTyping && (
                  <div className="flex justify-start">
                    <div className="flex items-center gap-1 rounded-lg border bg-muted/40 px-3 py-2">
                      <span className="h-2 w-2 animate-bounce rounded-full bg-muted-foreground/60 [animation-delay:-0.3s]" />
                      <span className="h-2 w-2 animate-bounce rounded-full bg-muted-foreground/60 [animation-delay:-0.15s]" />
                      <span className="h-2 w-2 animate-bounce rounded-full bg-muted-foreground/60" />
                    </div>
                  </div>
                )}

                <div ref={messagesEndRef} />
              </div>

              {newMessageCount > 0 && (
                <button
                  type="button"
                  onClick={scrollToBottom}
                  className="sticky bottom-2 left-1/2 z-10 -translate-x-1/2 rounded-full bg-primary px-3 py-1 text-xs text-primary-foreground shadow-md transition-opacity hover:opacity-90"
                >
                  {newMessageCount} new message{newMessageCount > 1 ? 's' : ''} ↓
                </button>
              )}

              <form onSubmit={submit} className="space-y-3 border-t pt-4">
                <Textarea
                  value={data.body}
                  onChange={(event) => {
                    setData('body', event.target.value);
                    if (draftTimerRef.current) clearTimeout(draftTimerRef.current);
                    setDraftSaved(false);
                    if (conversation?.id) {
                      draftTimerRef.current = setTimeout(() => {
                        axiosWithCsrf
                          .post(`/shop/inbox/${conversation.id}/draft`, {
                            draft_body: event.target.value,
                          })
                          .then(() => setDraftSaved(true))
                          .catch(() => {});
                      }, 1000);
                    }
                  }}
                  onFocus={() => {
                    if (conversation?.id) {
                      axiosWithCsrf.post(`/shop/inbox/${conversation.id}/typing`).catch(() => {});
                    }
                  }}
                  placeholder="Type a reply..."
                />
                {draftSaved && <p className="text-xs text-muted-foreground">Draft saved</p>}
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

                {showSchedule && (
                  <div className="flex items-center gap-2 rounded-md border bg-muted/30 p-3">
                    <CalendarClock className="h-4 w-4 text-muted-foreground" />
                    <Input
                      type="datetime-local"
                      value={scheduleAt}
                      onChange={(e) => setScheduleAt(e.target.value)}
                      className="h-9 flex-1"
                    />
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => {
                        setShowSchedule(false);
                        setScheduleAt('');
                      }}
                    >
                      Cancel
                    </Button>
                  </div>
                )}

                {scheduledMessages.length > 0 && (
                  <div className="space-y-2 rounded-md border border-primary/20 bg-primary/5 p-3">
                    <p className="flex items-center gap-1.5 text-xs font-medium text-primary">
                      <CalendarClock className="h-3.5 w-3.5" />
                      Scheduled Messages ({scheduledMessages.length})
                    </p>
                    {scheduledMessages.map((sm) => (
                      <div key={sm.id} className="flex items-center justify-between gap-2 text-xs">
                        <div className="flex-1 truncate">
                          <span className="text-muted-foreground">{time(sm.scheduled_at)}</span>
                          <span className="ml-2">{sm.body}</span>
                        </div>
                        <button
                          type="button"
                          onClick={() => cancelScheduledMessage(sm.id)}
                          className="shrink-0 text-muted-foreground hover:text-destructive"
                        >
                          <Trash2 className="h-3 w-3" />
                        </button>
                      </div>
                    ))}
                  </div>
                )}

                {showBroadcast && (
                  <div className="space-y-3 rounded-md border bg-muted/30 p-3">
                    <div className="flex items-center justify-between">
                      <p className="flex items-center gap-1.5 text-sm font-medium">
                        <Megaphone className="h-4 w-4" />
                        Broadcast to Other Conversations
                      </p>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                          setShowBroadcast(false);
                          setBroadcastIds([]);
                          setBroadcastResult(null);
                        }}
                      >
                        Cancel
                      </Button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                      Select conversations to send the same message to. Current conversation is
                      included automatically.
                    </p>
                    <div className="max-h-48 space-y-1 overflow-y-auto">
                      {merge_candidates.map((mc) => (
                        <label
                          key={mc.id}
                          className="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-xs hover:bg-muted/50"
                        >
                          <input
                            type="checkbox"
                            checked={broadcastIds.includes(mc.id)}
                            onChange={() => toggleBroadcastId(mc.id)}
                            className="h-3.5 w-3.5"
                          />
                          <span className="flex-1 truncate">
                            {mc.customer?.name ?? mc.identity?.display_name ?? 'Unknown'}
                            {mc.last_message_preview && (
                              <span className="ml-1 text-muted-foreground">
                                — {mc.last_message_preview.slice(0, 40)}
                              </span>
                            )}
                          </span>
                          <Badge variant="outline" className="text-[10px]">
                            {mc.status}
                          </Badge>
                        </label>
                      ))}
                    </div>
                    <Button
                      type="button"
                      size="sm"
                      onClick={sendBroadcast}
                      disabled={broadcastSending || !data.body.trim() || broadcastIds.length === 0}
                    >
                      <Megaphone className="mr-1.5 h-3 w-3" />
                      {broadcastSending
                        ? 'Sending...'
                        : `Broadcast to ${broadcastIds.length + 1} conversation${broadcastIds.length === 0 ? '' : 's'}`}
                    </Button>
                  </div>
                )}

                {broadcastResult && (
                  <div className="flex items-center gap-3 rounded-md border bg-muted/30 p-2 text-xs">
                    <span className="text-green-600">Sent: {broadcastResult.sent}</span>
                    {broadcastResult.failed > 0 && (
                      <span className="text-destructive">Failed: {broadcastResult.failed}</span>
                    )}
                    {broadcastResult.skipped > 0 && (
                      <span className="text-muted-foreground">
                        Skipped: {broadcastResult.skipped}
                      </span>
                    )}
                    <button
                      type="button"
                      onClick={() => setBroadcastResult(null)}
                      className="ml-auto text-muted-foreground hover:text-foreground"
                    >
                      ✕
                    </button>
                  </div>
                )}

                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() => setShowQuickReplies((v) => !v)}
                    >
                      <Plus className="mr-1.5 h-3 w-3" />
                      Quick Reply Buttons
                    </Button>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() => setShowSchedule((v) => !v)}
                    >
                      <CalendarClock className="mr-1.5 h-3 w-3" />
                      Schedule
                    </Button>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() => setShowBroadcast((v) => !v)}
                    >
                      <Megaphone className="mr-1.5 h-3 w-3" />
                      Broadcast
                    </Button>
                  </div>
                  <Button type="submit" disabled={processing}>
                    <Send className="mr-1.5 h-4 w-4" />
                    {showSchedule ? 'Schedule Send' : 'Send / Log Reply'}
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
                  className={`h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-medium ${statusBadgeClass(conversation.status, statusColor(conversation.status, statusLabels))}`}
                  style={statusBadgeStyle(statusColor(conversation.status, statusLabels))}
                >
                  {statuses.map((status) => {
                    const permitted =
                      allowedTransitions(conversation.status, userRole).includes(status) ||
                      status === conversation.status;
                    return (
                      <option key={status} value={status} disabled={!permitted}>
                        {label(status, statusLabels)}
                        {!permitted ? ' (not allowed)' : ''}
                      </option>
                    );
                  })}
                </select>
              </CardContent>
            </Card>

            {conversation.customer && showEditForm && (
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
                      <Badge
                        variant="outline"
                        className={riskBadgeClass(conversation.customer.risk_level)}
                      >
                        {conversation.customer.risk_level} risk
                      </Badge>
                    ) : null}
                  </div>
                  {conversation.customer ? (
                    <>
                      <div className="flex items-center gap-1.5">
                        <p className="text-muted-foreground">
                          {conversation.customer?.normalized_phone ??
                            conversation.customer?.phone ??
                            'No phone detected'}
                        </p>
                        {conversation.customer?.normalized_phone && (
                          <button
                            type="button"
                            onClick={() =>
                              copyToClipboard(
                                conversation.customer?.normalized_phone ?? '',
                                'phone'
                              )
                            }
                            className="text-muted-foreground hover:text-foreground"
                            title="Copy phone"
                          >
                            {copiedField === 'phone' ? (
                              <CheckCircle2 className="h-3.5 w-3.5 text-green-600" />
                            ) : (
                              <Copy className="h-3.5 w-3.5" />
                            )}
                          </button>
                        )}
                      </div>
                      <p className="text-muted-foreground">
                        PSID: {conversation.identity?.provider_user_id ?? 'unknown'}
                      </p>
                    </>
                  ) : (
                    <p className="text-xs text-muted-foreground italic">
                      No customer record linked. Customer details will appear here once matched.
                    </p>
                  )}
                </div>

                {conversation.customer && (
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
                )}

                {conversation.customer?.blacklist_reason && (
                  <p className="rounded-lg border border-destructive/30 bg-destructive/10 p-2 text-xs text-destructive">
                    {conversation.customer.blacklist_reason}
                  </p>
                )}

                {conversation.customer && (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="w-full justify-between"
                    onClick={() => setShowEditForm((v) => !v)}
                  >
                    <span className="flex items-center gap-1.5">
                      <Pencil className="h-3.5 w-3.5" />
                      Edit Customer Details
                    </span>
                    {showEditForm ? (
                      <ChevronUp className="h-4 w-4" />
                    ) : (
                      <ChevronDown className="h-4 w-4" />
                    )}
                  </Button>
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
                <div className="flex items-start justify-between gap-2">
                  <p>{customerAddress(conversation)}</p>
                  {customerAddress(conversation) && (
                    <button
                      type="button"
                      onClick={() => copyToClipboard(customerAddress(conversation), 'address')}
                      className="shrink-0 text-muted-foreground hover:text-foreground"
                      title="Copy address"
                    >
                      {copiedField === 'address' ? (
                        <CheckCircle2 className="h-3.5 w-3.5 text-green-600" />
                      ) : (
                        <Copy className="h-3.5 w-3.5" />
                      )}
                    </button>
                  )}
                </div>
                {conversation.customer?.landmark && (
                  <p className="text-muted-foreground">
                    Landmark: {conversation.customer.landmark}
                  </p>
                )}
                <div className="grid gap-2">
                  {savedAddresses.length > 0 && (
                    <div className="space-y-2">
                      <div>
                        <Label htmlFor="address_select" className="text-xs text-muted-foreground">
                          Saved addresses ({savedAddresses.length})
                        </Label>
                        <select
                          id="address_select"
                          value={selectedAddressId}
                          onChange={(e) => setSelectedAddressId(e.target.value)}
                          className="mt-1 h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                          <option value="">Use customer profile address</option>
                          {savedAddresses.map((addr) => (
                            <option key={addr.id} value={addr.id}>
                              {addr.label ? `${addr.label}: ` : ''}
                              {addr.canonical_address || 'No street'}
                              {addr.city_municipality ? `, ${addr.city_municipality}` : ''}
                              {addr.is_default ? ' (default)' : ''}
                            </option>
                          ))}
                        </select>
                      </div>
                      {selectedAddress && (
                        <div className="rounded-md border bg-muted/30 p-2 text-xs">
                          <p className="font-medium">
                            {selectedAddress.label || 'Selected address'}
                          </p>
                          <p className="text-muted-foreground">
                            {selectedAddress.canonical_address || 'No street address'}
                          </p>
                          {(selectedAddress.barangay ||
                            selectedAddress.city_municipality ||
                            selectedAddress.province) && (
                            <p className="text-muted-foreground">
                              {[
                                selectedAddress.barangay,
                                selectedAddress.city_municipality,
                                selectedAddress.province,
                              ]
                                .filter(Boolean)
                                .join(', ')}
                            </p>
                          )}
                          {selectedAddress.landmark && (
                            <p className="text-muted-foreground">
                              Landmark: {selectedAddress.landmark}
                            </p>
                          )}
                        </div>
                      )}
                    </div>
                  )}
                  <Button asChild size="sm">
                    <Link href={createOrderHref}>
                      <ShoppingCart className="mr-1.5 h-4 w-4" />
                      {selectedAddress ? 'Create Order with Selected Address' : 'Create Order'}
                    </Link>
                  </Button>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <div className="flex items-center justify-between">
                  <CardTitle className="flex items-center gap-2">
                    <History className="h-5 w-5" />
                    Recent Orders
                  </CardTitle>
                  {conversation.customer && (
                    <Button asChild size="sm" variant="ghost">
                      <Link href={`/shop/customers/${conversation.customer.id}`}>View All</Link>
                    </Button>
                  )}
                </div>
                {conversation.customer && (conversation.customer.total_orders ?? 0) > 0 && (
                  <div className="flex flex-wrap gap-2 text-xs">
                    <span className="rounded-full bg-muted px-2 py-0.5 text-muted-foreground">
                      Total: {conversation.customer.total_orders}
                    </span>
                    <span className="rounded-full border border-green-500/30 px-2 py-0.5 text-green-600">
                      Successful: {conversation.customer.successful_orders ?? 0}
                    </span>
                    <span className="rounded-full border border-destructive/30 px-2 py-0.5 text-destructive">
                      Returned: {conversation.customer.returned_orders ?? 0}
                    </span>
                  </div>
                )}
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
                        <div className="min-w-0">
                          <p className="text-sm font-medium">{order.order_number}</p>
                          <p className="truncate text-xs text-muted-foreground">
                            {order.product?.name ?? 'No product'}
                          </p>
                        </div>
                        <Badge variant="outline" className={orderStatusBadgeClass(order.status)}>
                          {order.status}
                        </Badge>
                      </div>
                      <div className="mt-2 flex items-center justify-between text-xs text-muted-foreground">
                        <span>{time(order.created_at)}</span>
                        <span>{money(order.total_amount)}</span>
                      </div>
                      {order.receiver_address && (
                        <p
                          className="mt-1.5 truncate text-xs text-muted-foreground"
                          title={order.receiver_address}
                        >
                          {order.receiver_address}
                        </p>
                      )}
                    </Link>
                  ))
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <MessageSquare className="h-5 w-5" />
                  Remarks &amp; Notes
                </CardTitle>
                <CardDescription>Internal notes visible to your team only</CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                <form onSubmit={submitRemark} className="space-y-2">
                  <Textarea
                    value={newRemark}
                    onChange={(e) => setNewRemark(e.target.value)}
                    placeholder="Add an internal note..."
                    className="min-h-[60px] text-sm"
                  />
                  <div className="flex justify-end">
                    <Button type="submit" size="sm" disabled={!newRemark.trim()}>
                      <Plus className="mr-1.5 h-3.5 w-3.5" />
                      Add Note
                    </Button>
                  </div>
                </form>
                {initialRemarks.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No remarks yet.</p>
                ) : (
                  <div className="space-y-2">
                    {initialRemarks.map((remark) => (
                      <div key={remark.id} className="group rounded-lg border p-3 text-sm">
                        <div className="flex items-start justify-between gap-2">
                          <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2 text-xs text-muted-foreground">
                              <span className="font-medium text-foreground">
                                {remark.user_name}
                              </span>
                              <span>{time(remark.created_at)}</span>
                            </div>
                            <p className="mt-1 whitespace-pre-wrap break-words">{remark.body}</p>
                          </div>
                          <button
                            type="button"
                            onClick={() => deleteRemark(remark.id)}
                            className="shrink-0 text-muted-foreground opacity-0 transition-opacity hover:text-destructive group-hover:opacity-100"
                            title="Delete remark"
                          >
                            <Trash2 className="h-3.5 w-3.5" />
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <PackageCheck className="h-5 w-5" />
                  Conversation Details
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-3 text-sm">
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <p className="text-xs font-medium text-muted-foreground">Source</p>
                    <p className="capitalize">{conversation.channel ?? 'messenger'}</p>
                  </div>
                  <div>
                    <p className="text-xs font-medium text-muted-foreground">Page</p>
                    <p>{conversation.facebook_page?.page_name ?? 'Unknown'}</p>
                  </div>
                  <div>
                    <p className="text-xs font-medium text-muted-foreground">Created</p>
                    <p>{time(conversation.created_at ?? null) || '—'}</p>
                  </div>
                  <div>
                    <p className="text-xs font-medium text-muted-foreground">Last Message</p>
                    <p>{time(conversation.last_message_at ?? null) || '—'}</p>
                  </div>
                  <div>
                    <p className="text-xs font-medium text-muted-foreground">First Response</p>
                    <p>{time(conversation.first_response_at ?? null) || '—'}</p>
                  </div>
                  <div>
                    <p className="text-xs font-medium text-muted-foreground">Resolved</p>
                    <p>{time(conversation.resolved_at ?? null) || '—'}</p>
                  </div>
                </div>
                {conversation.last_message_preview && (
                  <div>
                    <p className="text-xs font-medium text-muted-foreground">
                      Last Message Preview
                    </p>
                    <p
                      className="truncate text-muted-foreground"
                      title={conversation.last_message_preview}
                    >
                      {conversation.last_message_preview}
                    </p>
                  </div>
                )}
                {conversation.thread_key && (
                  <div>
                    <p className="text-xs font-medium text-muted-foreground">Thread Key</p>
                    <p className="font-mono text-xs text-muted-foreground">
                      {conversation.thread_key}
                    </p>
                  </div>
                )}
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Webhook Status</p>
                  <Badge
                    variant="outline"
                    className={
                      conversation.facebook_page?.webhook_status === 'active'
                        ? 'border-green-500/40 text-green-600 bg-green-50 dark:bg-green-950/30'
                        : 'border-muted text-muted-foreground'
                    }
                  >
                    {conversation.facebook_page?.webhook_status ?? 'unknown'}
                  </Badge>
                </div>
                {conversation.facebook_page && (
                  <div>
                    <p className="text-xs font-medium text-muted-foreground">Page ID</p>
                    <p className="font-mono text-xs text-muted-foreground">
                      {conversation.facebook_page.page_id}
                    </p>
                  </div>
                )}
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
              <CardContent className="space-y-3">
                <div className="flex items-center gap-2">
                  {conversation.sentiment === 'positive' && (
                    <Badge className="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">
                      😊 Positive
                    </Badge>
                  )}
                  {conversation.sentiment === 'negative' && (
                    <Badge className="bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300">
                      😟 Negative
                    </Badge>
                  )}
                  {conversation.sentiment === 'neutral' && (
                    <Badge variant="outline">😐 Neutral</Badge>
                  )}
                  <span className="text-sm font-medium text-muted-foreground">
                    {Number(conversation.sentiment_score).toFixed(2)}
                  </span>
                </div>
                <div className="relative h-2 w-full overflow-hidden rounded-full bg-muted">
                  <div
                    className={`h-full rounded-full transition-all ${
                      conversation.sentiment === 'positive'
                        ? 'bg-green-500'
                        : conversation.sentiment === 'negative'
                          ? 'bg-red-500'
                          : 'bg-muted-foreground/40'
                    }`}
                    style={{
                      width: `${Math.min(Math.abs(Number(conversation.sentiment_score)) * 100, 100)}%`,
                    }}
                  />
                </div>
                <div className="flex justify-between text-[10px] text-muted-foreground">
                  <span>−1.0</span>
                  <span>0</span>
                  <span>+1.0</span>
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

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <History className="h-5 w-5" />
                  Activity Log
                </CardTitle>
                <CardDescription>Unified timeline of all conversation events</CardDescription>
              </CardHeader>
              <CardContent>
                {activityLog.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No activity recorded yet.</p>
                ) : (
                  <div className="relative space-y-3 before:absolute before:left-3 before:top-1 before:h-full before:w-px before:bg-border">
                    {activityLog.map((entry) => (
                      <div key={entry.id} className="relative flex gap-3 pl-7">
                        <span
                          className={`absolute left-0 top-1 flex h-6 w-6 items-center justify-center rounded-full border ${
                            entry.type === 'flag'
                              ? 'border-destructive/40 bg-destructive/10 text-destructive'
                              : entry.type === 'status'
                                ? 'border-blue-500/40 bg-blue-50 text-blue-600 dark:bg-blue-950/30'
                                : entry.type === 'assignment'
                                  ? 'border-purple-500/40 bg-purple-50 text-purple-600 dark:bg-purple-950/30'
                                  : entry.type === 'remark'
                                    ? 'border-amber-500/40 bg-amber-50 text-amber-600 dark:bg-amber-950/30'
                                    : 'border-muted bg-muted text-muted-foreground'
                          }`}
                        >
                          {entry.type === 'assignment'
                            ? '→'
                            : entry.type === 'status'
                              ? '⟳'
                              : entry.type === 'remark'
                                ? '✎'
                                : entry.type === 'flag'
                                  ? '!'
                                  : '⏸'}
                        </span>
                        <div className="min-w-0 flex-1">
                          <div className="flex items-center gap-2">
                            {entry.badge && (
                              <Badge
                                variant="outline"
                                className={`text-[10px] ${entry.badge.className ?? ''}`}
                              >
                                {entry.badge.text}
                              </Badge>
                            )}
                            {entry.actor && (
                              <span className="text-xs font-medium text-foreground">
                                {entry.actor}
                              </span>
                            )}
                          </div>
                          <p className="mt-0.5 text-xs text-foreground">{entry.description}</p>
                          {entry.timestamp && (
                            <p className="mt-0.5 text-[10px] text-muted-foreground">
                              {time(entry.timestamp)}
                            </p>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        </div>
      </div>

      {lightboxUrl && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
          onClick={() => setLightboxUrl(null)}
        >
          <button
            type="button"
            className="absolute right-4 top-4 rounded-md bg-background/80 p-2 text-foreground hover:bg-background"
            onClick={() => setLightboxUrl(null)}
          >
            <X className="h-5 w-5" />
          </button>
          <a
            href={lightboxUrl}
            download
            target="_blank"
            rel="noopener noreferrer"
            className="absolute bottom-4 right-4 rounded-md bg-background/80 p-2 text-foreground hover:bg-background"
            title="Download"
          >
            <Download className="h-5 w-5" />
          </a>
          <img
            src={lightboxUrl}
            alt="Attachment preview"
            className="max-h-[90vh] max-w-[90vw] rounded-lg object-contain"
            onClick={(e) => e.stopPropagation()}
          />
        </div>
      )}

      {showBlockModal && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
          onClick={() => setShowBlockModal(false)}
        >
          <div
            className="w-full max-w-md space-y-4 rounded-lg bg-background p-6 shadow-lg"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between">
              <h3 className="flex items-center gap-2 text-lg font-semibold">
                <Ban className="h-5 w-5 text-destructive" />
                Block Customer
              </h3>
              <button type="button" onClick={() => setShowBlockModal(false)}>
                <X className="h-5 w-5 text-muted-foreground" />
              </button>
            </div>
            <p className="text-sm text-muted-foreground">
              Blocking this customer will mark them as blacklisted and set risk level to
              BLACKLISTED. You can unblock them later.
            </p>
            <div className="space-y-2">
              <Label htmlFor="block_reason">Reason (optional)</Label>
              <Textarea
                id="block_reason"
                value={blockReason}
                onChange={(e) => setBlockReason(e.target.value)}
                placeholder="Enter reason for blocking..."
                className="min-h-[80px]"
              />
            </div>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => setShowBlockModal(false)}>
                Cancel
              </Button>
              <Button
                type="button"
                variant="destructive"
                onClick={() => {
                  router.post(
                    `/shop/inbox/${conversation.id}/block`,
                    { block: true, reason: blockReason || undefined },
                    { preserveScroll: true }
                  );
                  setShowBlockModal(false);
                  setBlockReason('');
                }}
              >
                <Ban className="mr-1.5 h-4 w-4" />
                Confirm Block
              </Button>
            </div>
          </div>
        </div>
      )}

      {showTransferModal && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
          onClick={() => setShowTransferModal(false)}
        >
          <div
            className="w-full max-w-md space-y-4 rounded-lg bg-background p-6 shadow-lg"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between">
              <h3 className="flex items-center gap-2 text-lg font-semibold">
                <ArrowRightLeft className="h-5 w-5" />
                Transfer Conversation
              </h3>
              <button type="button" onClick={() => setShowTransferModal(false)}>
                <X className="h-5 w-5 text-muted-foreground" />
              </button>
            </div>
            <p className="text-sm text-muted-foreground">
              Currently assigned to:{' '}
              <span className="font-medium text-foreground">
                {conversation.assigned_agent?.name ?? 'Unassigned'}
              </span>
            </p>
            <div className="space-y-2">
              <Label htmlFor="transfer_agent">Assign to agent</Label>
              <select
                id="transfer_agent"
                value={transferAgentId}
                onChange={(e) => setTransferAgentId(e.target.value)}
                className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              >
                <option value="">Unassigned</option>
                {agents.map((agent) => (
                  <option key={agent.id} value={agent.id}>
                    {agent.name} ({agent.role})
                  </option>
                ))}
              </select>
            </div>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => setShowTransferModal(false)}>
                Cancel
              </Button>
              <Button
                type="button"
                disabled={transferAgentId === (conversation.assigned_agent?.id?.toString() ?? '')}
                onClick={() => {
                  updateAssignment(transferAgentId);
                  setShowTransferModal(false);
                  setTransferAgentId('');
                }}
              >
                <ArrowRightLeft className="mr-1.5 h-4 w-4" />
                Transfer
              </Button>
            </div>
          </div>
        </div>
      )}
    </AppLayout>
  );
}
