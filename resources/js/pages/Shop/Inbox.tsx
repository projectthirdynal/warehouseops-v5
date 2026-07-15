import { useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
  BarChart3,
  CheckCheck,
  Check,
  Circle,
  EyeOff,
  Flag,
  Inbox,
  MessageSquare,
  MessageCircleWarning,
  MessagesSquare,
  Phone,
  Plus,
  Star,
  Store,
  UserCog,
  User,
  UserCheck,
  X,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

interface Page {
  id: number;
  page_id: string;
  page_name: string;
  connected_status?: string;
  webhook_status?: string;
  unread_count?: number;
}

interface Conversation {
  id: number;
  status: string;
  channel: string;
  priority: string;
  is_flagged: boolean;
  flag_reason: string | null;
  snoozed_until: string | null;
  reminder_at: string | null;
  sentiment: string;
  sentiment_score: number;
  tags: { id: number; name: string; color: string }[];
  last_message_preview: string | null;
  last_message_at: string | null;
  unread_count: number;
  messages_count: number;
  sla?: {
    elapsed_minutes: number | null;
    threshold_minutes: number | null;
    remaining_minutes: number | null;
    status: string;
  };
  facebook_page?: Page | null;
  customer?: { id: number; name: string; phone: string; normalized_phone?: string | null } | null;
  identity?: { id: number; display_name?: string | null; phone_detected?: string | null } | null;
  assigned_agent?: { id: number; name: string } | null;
}

interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
}

interface AssignmentRule {
  id: number;
  facebook_page_id: number;
  user_id: number;
  agent_name: string | null;
  is_active: boolean;
}

interface PendingComment {
  id: number;
  conversation_id: number;
  facebook_page_id: number;
  customer_identity_id: number | null;
  body: string | null;
  sent_at: string | null;
  moderation_status: string;
  facebook_page?: { id: number; page_name: string; page_id: string } | null;
  identity?: { id: number; display_name: string | null; provider_user_id: string | null } | null;
  conversation?: { id: number; thread_key: string; channel: string } | null;
}

interface PageCannedResponse {
  id: number;
  name: string;
  message: string;
  category: string | null;
  is_active: boolean;
  sort_order: number;
  facebook_page_id: number;
  page_name: string | null;
}

interface AgentStatusDotProps {
  status: string;
  className?: string;
}

function AgentStatusDot({ status, className = '' }: AgentStatusDotProps) {
  const colors: Record<string, string> = {
    online: 'bg-green-500',
    away: 'bg-amber-500',
    offline: 'bg-muted-foreground',
  };
  return (
    <Circle
      className={`h-2.5 w-2.5 fill-current ${colors[status] ?? colors.offline} ${className}`}
    />
  );
}

interface Props {
  conversations: Paginated<Conversation>;
  pages: Page[];
  favorite_page_ids?: number[];
  assignment_rules?: AssignmentRule[];
  pending_comments?: PendingComment[];
  page_canned_responses?: PageCannedResponse[];
  agents: {
    id: number;
    name: string;
    role: string;
    status: string;
    active_conversations: number;
    auto_assign_enabled: boolean;
    product_skills: string[];
    regions: string[];
    category_skills: string[];
    performance_score: number;
    total_assigned_30d: number;
    resolved_30d: number;
    resolution_rate: number;
    avg_response_seconds_30d: number | null;
    max_active_conversations: number;
    overflow_enabled: boolean;
    shift_start: string | null;
    shift_end: string | null;
    idle_threshold_minutes: number;
    is_idle: boolean;
  }[];
  can_view_all?: boolean;
  current_user_id?: number;
  user_role?: string;
  my_status?: string;
  statuses: string[];
  status_counts?: Record<string, number>;
  sla_thresholds?: Record<string, number | null>;
  status_labels?: Record<number, Record<string, string>>;
  priorities: string[];
  tags: { id: number; name: string; color: string }[];
  filters: {
    page_id?: string;
    status?: string;
    assigned_agent_id?: string;
    priority?: string;
    flagged?: string;
    tag_id?: string;
    snoozed?: string;
  };
  workload_report?: {
    total_active: number;
    total_agents: number;
    avg_per_agent: number;
    max_assigned: number;
    min_assigned: number;
    imbalance_ratio: number;
    status: string;
    recommendations: {
      agent_id: number;
      agent_name: string;
      active: number;
      max: number;
      suggestion: string;
    }[];
    distribution: {
      agent_id: number;
      agent_name: string;
      active: number;
      max: number;
      utilization: number;
    }[];
  } | null;
}

function formatDate(value: string | null) {
  if (!value) return 'No messages yet';
  return new Date(value).toLocaleString();
}

function label(value: string) {
  return value.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase());
}

function statusLabel(
  status: string,
  pageId: number | undefined,
  labels: Record<number, Record<string, string>>
): string {
  if (pageId && labels[pageId]?.[status]) {
    return labels[pageId][status];
  }
  return label(status);
}

export default function ShopInbox({
  conversations,
  pages,
  favorite_page_ids = [],
  assignment_rules = [],
  pending_comments = [],
  page_canned_responses = [],
  agents,
  can_view_all = true,
  user_role: userRole = 'agent',
  my_status = 'offline',
  statuses,
  status_counts: statusCounts = {},
  status_labels: statusLabels = {},
  priorities = ['low', 'normal', 'high', 'urgent'],
  tags = [],
  workload_report: workloadReport = null,
  filters = {},
}: Props) {
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [bulkStatus, setBulkStatus] = useState<string>('resolved');
  const [bulkAgentId, setBulkAgentId] = useState<string>('');
  const [pageSearch, setPageSearch] = useState('');
  const [showRules, setShowRules] = useState(false);
  const [showModeration, setShowModeration] = useState(false);
  const [showCanned, setShowCanned] = useState(false);
  const [rulePageId, setRulePageId] = useState('');
  const [ruleAgentId, setRuleAgentId] = useState('');
  const [cannedPageId, setCannedPageId] = useState('');
  const [cannedName, setCannedName] = useState('');
  const [cannedMessage, setCannedMessage] = useState('');
  const [labelPageId, setLabelPageId] = useState('');
  const [labelStatus, setLabelStatus] = useState('');
  const [labelText, setLabelText] = useState('');
  const [skillEditId, setSkillEditId] = useState<number | null>(null);
  const [skillProductInput, setSkillProductInput] = useState('');
  const [skillRegionInput, setSkillRegionInput] = useState('');
  const [skillCategoryInput, setSkillCategoryInput] = useState('');
  const [queueEditId, setQueueEditId] = useState<number | null>(null);
  const [queueMaxInput, setQueueMaxInput] = useState('15');
  const [queueOverflowInput, setQueueOverflowInput] = useState(true);
  const [shiftEditId, setShiftEditId] = useState<number | null>(null);
  const [shiftStartInput, setShiftStartInput] = useState('');
  const [shiftEndInput, setShiftEndInput] = useState('');
  const [idleEditId, setIdleEditId] = useState<number | null>(null);
  const [idleThresholdInput, setIdleThresholdInput] = useState('15');

  const filteredPages = useMemo(() => {
    const query = pageSearch.toLowerCase().trim();
    const filtered = query
      ? pages.filter(
          (page) =>
            page.page_name.toLowerCase().includes(query) ||
            page.page_id.toLowerCase().includes(query)
        )
      : pages;
    return [...filtered].sort((a, b) => {
      const aFav = favorite_page_ids.includes(a.id) ? 0 : 1;
      const bFav = favorite_page_ids.includes(b.id) ? 0 : 1;
      return aFav - bFav;
    });
  }, [pages, pageSearch, favorite_page_ids]);

  const toggleFavorite = (pageId: number, e: React.MouseEvent) => {
    e.stopPropagation();
    e.preventDefault();
    router.post('/shop/inbox/page-favorite', { page_id: pageId }, { preserveState: true });
  };

  const addAssignmentRule = () => {
    if (!rulePageId || !ruleAgentId) return;
    router.post(
      '/shop/inbox/assignment-rules',
      { facebook_page_id: Number(rulePageId), user_id: Number(ruleAgentId) },
      {
        preserveState: true,
        onSuccess: () => {
          setRulePageId('');
          setRuleAgentId('');
        },
      }
    );
  };

  const removeAssignmentRule = (ruleId: number) => {
    router.delete('/shop/inbox/assignment-rules', {
      data: { rule_id: ruleId },
      preserveState: true,
    });
  };

  const moderateComment = (messageId: number, action: 'approve' | 'hide') => {
    router.post(
      '/shop/inbox/moderate-comment',
      { message_id: messageId, action },
      { preserveState: true }
    );
  };

  const addCannedResponse = () => {
    if (!cannedPageId || !cannedName || !cannedMessage) return;
    router.post(
      '/shop/inbox/page-canned-responses',
      {
        facebook_page_id: Number(cannedPageId),
        name: cannedName,
        message: cannedMessage,
      },
      {
        preserveState: true,
        onSuccess: () => {
          setCannedPageId('');
          setCannedName('');
          setCannedMessage('');
        },
      }
    );
  };

  const removeCannedResponse = (templateId: number) => {
    router.delete('/shop/inbox/page-canned-responses', {
      data: { template_id: templateId },
      preserveState: true,
    });
  };

  const saveStatusLabel = () => {
    if (!labelPageId || !labelStatus || !labelText) return;
    router.post(
      '/shop/inbox/status-labels',
      { page_id: Number(labelPageId), status: labelStatus, label: labelText },
      {
        preserveState: true,
        onSuccess: () => {
          setLabelPageId('');
          setLabelStatus('');
          setLabelText('');
        },
      }
    );
  };

  const removeStatusLabel = (pageId: number, status: string) => {
    router.delete('/shop/inbox/status-labels', {
      data: { page_id: pageId, status },
      preserveState: true,
    });
  };

  const isSupervisor = ['supervisor', 'admin', 'superadmin'].includes(userRole);

  const allowedTransitions = (currentStatus: string): string[] => {
    const transitions: Record<string, string[]> = {
      new: ['assigned', 'awaiting_customer', 'resolved', 'archived'],
      assigned: ['awaiting_customer', 'resolved', 'archived', 'new'],
      awaiting_customer: ['assigned', 'resolved', 'archived'],
      resolved: ['assigned', 'awaiting_customer', 'archived'],
      archived: ['resolved', 'assigned'],
    };
    const agentAllowed = ['assigned', 'awaiting_customer', 'resolved'];
    const allowed = transitions[currentStatus] ?? [];
    return isSupervisor ? allowed : allowed.filter((s) => agentAllowed.includes(s));
  };

  const updateFilter = (next: Record<string, string | undefined>) => {
    router.get('/shop/inbox', { ...filters, ...next }, { preserveState: true });
  };

  const changeStatus = (conversationId: number, status: string) => {
    router.patch(
      `/shop/inbox/${conversationId}/status`,
      { status },
      { preserveScroll: true, preserveState: true }
    );
  };

  const statusBadgeClass = (status: string) => {
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
  };

  const formatSlaMinutes = (minutes: number): string => {
    if (minutes < 60) return `${minutes}m`;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m > 0 ? `${h}h ${m}m` : `${h}h`;
  };

  const slaBadgeClass = (slaStatus: string) => {
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
  };

  const toggleSelect = (id: number) => {
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((sid) => sid !== id) : [...prev, id]
    );
  };

  const toggleSelectAll = () => {
    if (selectedIds.length === conversations.data.length) {
      setSelectedIds([]);
    } else {
      setSelectedIds(conversations.data.map((c) => c.id));
    }
  };

  const submitBulk = () => {
    if (selectedIds.length === 0) return;
    router.post(
      '/shop/inbox/bulk-status',
      { conversation_ids: selectedIds, status: bulkStatus },
      {
        preserveScroll: true,
        onSuccess: () => setSelectedIds([]),
      }
    );
  };

  const submitBulkAssign = () => {
    if (selectedIds.length === 0) return;
    const agentId = bulkAgentId === 'unassign' ? null : bulkAgentId || null;
    router.post(
      '/shop/inbox/bulk-assign',
      { conversation_ids: selectedIds, assigned_agent_id: agentId },
      {
        preserveScroll: true,
        onSuccess: () => {
          setSelectedIds([]);
          setBulkAgentId('');
        },
      }
    );
  };

  const startEditSkills = (agent: {
    id: number;
    product_skills: string[];
    regions: string[];
    category_skills: string[];
  }) => {
    setSkillEditId(agent.id);
    setSkillProductInput(agent.product_skills.join(', '));
    setSkillRegionInput(agent.regions.join(', '));
    setSkillCategoryInput(agent.category_skills.join(', '));
  };

  const saveSkills = (agentId: number) => {
    const parse = (s: string) =>
      s
        .split(',')
        .map((v) => v.trim())
        .filter(Boolean);
    router.post(
      '/shop/inbox/agent-skills',
      {
        user_id: agentId,
        product_skills: parse(skillProductInput),
        regions: parse(skillRegionInput),
        category_skills: parse(skillCategoryInput),
      },
      {
        preserveState: true,
        onSuccess: () => setSkillEditId(null),
      }
    );
  };

  const startEditQueue = (agent: {
    id: number;
    max_active_conversations: number;
    overflow_enabled: boolean;
  }) => {
    setQueueEditId(agent.id);
    setQueueMaxInput(String(agent.max_active_conversations));
    setQueueOverflowInput(agent.overflow_enabled);
  };

  const saveQueueLimit = (agentId: number) => {
    router.post(
      '/shop/inbox/agent-queue-limit',
      {
        user_id: agentId,
        max_active_conversations: parseInt(queueMaxInput, 10) || 15,
        overflow_enabled: queueOverflowInput,
      },
      {
        preserveState: true,
        onSuccess: () => setQueueEditId(null),
      }
    );
  };

  const startEditShift = (agent: {
    id: number;
    shift_start: string | null;
    shift_end: string | null;
  }) => {
    setShiftEditId(agent.id);
    setShiftStartInput(agent.shift_start ?? '');
    setShiftEndInput(agent.shift_end ?? '');
  };

  const saveShiftSchedule = (agentId: number) => {
    router.post(
      '/shop/inbox/agent-shift',
      {
        user_id: agentId,
        shift_start: shiftStartInput || null,
        shift_end: shiftEndInput || null,
      },
      {
        preserveState: true,
        onSuccess: () => setShiftEditId(null),
      }
    );
  };

  const startEditIdle = (agent: { id: number; idle_threshold_minutes: number }) => {
    setIdleEditId(agent.id);
    setIdleThresholdInput(String(agent.idle_threshold_minutes));
  };

  const saveIdleThreshold = (agentId: number) => {
    router.post(
      '/shop/inbox/agent-idle-threshold',
      {
        user_id: agentId,
        idle_threshold_minutes: parseInt(idleThresholdInput, 10) || 15,
      },
      {
        preserveState: true,
        onSuccess: () => setIdleEditId(null),
      }
    );
  };

  return (
    <AppLayout>
      <Head title="Shop Inbox" />

      <div className="space-y-4">
        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
          <div>
            <h1 className="text-xl font-bold tracking-tight font-display">Shop Inbox</h1>
            <p className="text-muted-foreground">
              Messenger conversations from connected Facebook Pages
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" size="sm" asChild>
              <Link href="/shop/analytics">
                <BarChart3 className="h-4 w-4" />
                Analytics
              </Link>
            </Button>
            <Button
              variant={showRules ? 'default' : 'outline'}
              size="sm"
              onClick={() => setShowRules(!showRules)}
            >
              <UserCog className="h-4 w-4" />
              Rules
              {assignment_rules.length > 0 && (
                <Badge className="ml-1 bg-primary-foreground text-primary">
                  {assignment_rules.length}
                </Badge>
              )}
            </Button>
            <Button
              variant={showModeration ? 'default' : 'outline'}
              size="sm"
              onClick={() => setShowModeration(!showModeration)}
            >
              <MessageCircleWarning className="h-4 w-4" />
              Moderation
              {pending_comments.length > 0 && (
                <Badge className="ml-1 bg-primary-foreground text-primary">
                  {pending_comments.length}
                </Badge>
              )}
            </Button>
            <Button
              variant={showCanned ? 'default' : 'outline'}
              size="sm"
              onClick={() => setShowCanned(!showCanned)}
            >
              <MessagesSquare className="h-4 w-4" />
              Canned
              {page_canned_responses.length > 0 && (
                <Badge className="ml-1 bg-primary-foreground text-primary">
                  {page_canned_responses.length}
                </Badge>
              )}
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() =>
                router.post(
                  '/shop/inbox/agent-status',
                  { is_available: my_status !== 'online' },
                  { preserveState: true }
                )
              }
            >
              <AgentStatusDot status={my_status} className="mr-1" />
              {my_status === 'online' ? 'Online' : my_status === 'away' ? 'Away' : 'Offline'}
            </Button>
            <Select
              value={filters.page_id ?? 'all'}
              onValueChange={(value) =>
                updateFilter({ page_id: value === 'all' ? undefined : value })
              }
            >
              <SelectTrigger className="w-[180px]">
                <SelectValue placeholder="All Pages" />
              </SelectTrigger>
              <SelectContent>
                <div className="p-2 border-b sticky top-0 bg-background z-10">
                  <Input
                    placeholder="Search pages..."
                    value={pageSearch}
                    onChange={(e) => setPageSearch(e.target.value)}
                    onClick={(e) => e.stopPropagation()}
                    onKeyDown={(e) => e.stopPropagation()}
                    className="h-8 text-sm"
                  />
                </div>
                <SelectItem value="all">All Pages</SelectItem>
                {filteredPages.map((page) => {
                  const isFavorite = favorite_page_ids.includes(page.id);
                  return (
                    <SelectItem key={page.id} value={page.id.toString()}>
                      <span className="flex items-center justify-between gap-2">
                        <span className="flex items-center gap-1.5">
                          <button
                            type="button"
                            onClick={(e) => toggleFavorite(page.id, e)}
                            className="shrink-0"
                          >
                            <Star
                              className={`h-3.5 w-3.5 ${
                                isFavorite
                                  ? 'fill-yellow-400 text-yellow-400'
                                  : 'text-muted-foreground'
                              }`}
                            />
                          </button>
                          <span
                            className={`h-2 w-2 shrink-0 rounded-full ${
                              page.connected_status === 'connected' ? 'bg-green-500' : 'bg-red-500'
                            }`}
                            title={
                              page.connected_status === 'connected' ? 'Connected' : 'Disconnected'
                            }
                          />
                          {page.page_name}
                          {page.webhook_status && page.webhook_status !== 'subscribed' && (
                            <span
                              className={`h-1.5 w-1.5 shrink-0 rounded-full ${
                                page.webhook_status === 'needs_retry'
                                  ? 'bg-orange-500'
                                  : 'bg-yellow-500'
                              }`}
                              title={`Webhook: ${page.webhook_status}`}
                            />
                          )}
                        </span>
                        {(page.unread_count ?? 0) > 0 && (
                          <Badge className="ml-auto bg-primary text-primary-foreground">
                            {page.unread_count}
                          </Badge>
                        )}
                      </span>
                    </SelectItem>
                  );
                })}
                {filteredPages.length === 0 && (
                  <div className="py-4 text-center text-sm text-muted-foreground">
                    No pages found
                  </div>
                )}
              </SelectContent>
            </Select>
            <Select
              value={filters.status ?? 'all'}
              onValueChange={(value) =>
                updateFilter({ status: value === 'all' ? undefined : value })
              }
            >
              <SelectTrigger className="w-[150px]">
                <SelectValue placeholder="All Statuses" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                {statuses.map((status) => (
                  <SelectItem key={status} value={status}>
                    {label(status)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {can_view_all && (
              <Select
                value={filters.assigned_agent_id ?? 'all'}
                onValueChange={(value) =>
                  updateFilter({ assigned_agent_id: value === 'all' ? undefined : value })
                }
              >
                <SelectTrigger className="w-[160px]">
                  <SelectValue placeholder="All Agents" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Agents</SelectItem>
                  <SelectItem value="me">My Conversations</SelectItem>
                  <SelectItem value="unassigned">Unassigned</SelectItem>
                  {agents.map((agent) => (
                    <SelectItem key={agent.id} value={agent.id.toString()}>
                      <span className="flex items-center gap-2">
                        <AgentStatusDot status={agent.status} />
                        {agent.name}
                        {agent.auto_assign_enabled && (
                          <span
                            className="text-[10px] font-medium text-blue-600"
                            title="Auto-assignment enabled"
                          >
                            AUTO
                          </span>
                        )}
                        {agent.active_conversations > 0 && (
                          <Badge
                            variant="secondary"
                            className={`ml-1 text-xs ${
                              agent.active_conversations >= 15
                                ? 'bg-red-500/10 text-red-600'
                                : agent.active_conversations >= 8
                                  ? 'bg-amber-500/10 text-amber-600'
                                  : 'bg-muted text-muted-foreground'
                            }`}
                          >
                            {agent.active_conversations}
                          </Badge>
                        )}
                      </span>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
            {!can_view_all && (
              <Select
                value={filters.assigned_agent_id ?? 'me'}
                onValueChange={(value) =>
                  updateFilter({ assigned_agent_id: value === 'me' ? undefined : value })
                }
              >
                <SelectTrigger className="w-[160px]">
                  <SelectValue placeholder="My Conversations" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="me">My Conversations</SelectItem>
                  <SelectItem value="unassigned">Unassigned</SelectItem>
                </SelectContent>
              </Select>
            )}
            <Select
              value={filters.priority ?? 'all'}
              onValueChange={(value) =>
                updateFilter({ priority: value === 'all' ? undefined : value })
              }
            >
              <SelectTrigger className="w-[150px]">
                <SelectValue placeholder="All Priorities" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Priorities</SelectItem>
                {priorities.map((p) => (
                  <SelectItem key={p} value={p}>
                    {label(p)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Select
              value={filters.tag_id ?? 'all'}
              onValueChange={(value) =>
                updateFilter({ tag_id: value === 'all' ? undefined : value })
              }
            >
              <SelectTrigger className="w-[150px]">
                <SelectValue placeholder="All Tags" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Tags</SelectItem>
                {tags.map((tag) => (
                  <SelectItem key={tag.id} value={tag.id.toString()}>
                    {tag.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Select
              value={filters.snoozed ?? 'all'}
              onValueChange={(value) =>
                updateFilter({ snoozed: value === 'all' ? undefined : value })
              }
            >
              <SelectTrigger className="w-[170px]">
                <SelectValue placeholder="All Snooze States" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Snooze States</SelectItem>
                <SelectItem value="none">Not Snoozed</SelectItem>
                <SelectItem value="active">Snoozed (Active)</SelectItem>
                <SelectItem value="expired">Snoozed (Expired)</SelectItem>
              </SelectContent>
            </Select>
            <Button
              variant={filters.flagged ? 'default' : 'outline'}
              size="sm"
              onClick={() => updateFilter({ flagged: filters.flagged ? undefined : '1' })}
            >
              <Flag className="h-4 w-4" />
              Flagged
            </Button>
          </div>
        </div>

        {showRules && (
          <Card className="mb-4">
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <UserCog className="h-4 w-4" />
                Page Assignment Rules
              </CardTitle>
              <CardDescription>
                New conversations from a page are auto-assigned to the configured agent.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              {assignment_rules.length > 0 && (
                <div className="space-y-1.5">
                  {assignment_rules.map((rule) => {
                    const page = pages.find((p) => p.id === rule.facebook_page_id);
                    return (
                      <div
                        key={rule.id}
                        className="flex items-center justify-between rounded-md border px-3 py-2 text-sm"
                      >
                        <span className="flex items-center gap-2">
                          <Badge variant="outline">
                            {page?.page_name ?? `Page #${rule.facebook_page_id}`}
                          </Badge>
                          <span className="text-muted-foreground">→</span>
                          <span>{rule.agent_name ?? `Agent #${rule.user_id}`}</span>
                        </span>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => removeAssignmentRule(rule.id)}
                        >
                          <X className="h-3.5 w-3.5" />
                        </Button>
                      </div>
                    );
                  })}
                </div>
              )}
              <div className="flex items-end gap-2">
                <div className="flex-1">
                  <label className="mb-1 block text-xs text-muted-foreground">Page</label>
                  <Select value={rulePageId} onValueChange={setRulePageId}>
                    <SelectTrigger className="w-full">
                      <SelectValue placeholder="Select page" />
                    </SelectTrigger>
                    <SelectContent>
                      {pages.map((page) => (
                        <SelectItem key={page.id} value={page.id.toString()}>
                          {page.page_name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="flex-1">
                  <label className="mb-1 block text-xs text-muted-foreground">Agent</label>
                  <Select value={ruleAgentId} onValueChange={setRuleAgentId}>
                    <SelectTrigger className="w-full">
                      <SelectValue placeholder="Select agent" />
                    </SelectTrigger>
                    <SelectContent>
                      {agents.map((agent) => (
                        <SelectItem key={agent.id} value={agent.id.toString()}>
                          {agent.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <Button
                  size="sm"
                  onClick={addAssignmentRule}
                  disabled={!rulePageId || !ruleAgentId}
                >
                  Add Rule
                </Button>
              </div>

              {can_view_all && (
                <div className="border-t pt-3">
                  <div className="mb-2">
                    <h4 className="text-sm font-medium">Round-Robin Auto-Assignment</h4>
                    <p className="text-xs text-muted-foreground">
                      New conversations without a page-specific rule are auto-assigned to available
                      agents with the fewest active conversations.
                    </p>
                  </div>
                  <div className="space-y-1.5">
                    {agents
                      .filter((a) => a.role === 'agent' || a.role === 'supervisor')
                      .map((agent) => (
                        <div
                          key={agent.id}
                          className="flex items-center justify-between rounded-md border px-3 py-2 text-sm"
                        >
                          <span className="flex items-center gap-2">
                            <AgentStatusDot status={agent.status} />
                            {agent.name}
                            <Badge variant="outline" className="text-xs">
                              {agent.active_conversations} active
                            </Badge>
                          </span>
                          <Button
                            variant={agent.auto_assign_enabled ? 'default' : 'outline'}
                            size="sm"
                            onClick={() =>
                              router.post(
                                '/shop/inbox/agent-auto-assign',
                                {
                                  user_id: agent.id,
                                  auto_assign_enabled: !agent.auto_assign_enabled,
                                },
                                { preserveState: true }
                              )
                            }
                          >
                            {agent.auto_assign_enabled ? 'Enabled' : 'Disabled'}
                          </Button>
                        </div>
                      ))}
                  </div>
                </div>
              )}

              {can_view_all && (
                <div className="border-t pt-3">
                  <div className="mb-2">
                    <h4 className="text-sm font-medium">Skill-Based Routing</h4>
                    <p className="text-xs text-muted-foreground">
                      Auto-assignment prioritizes agents whose skills match the conversation's page
                      category, customer region, and message keywords.
                    </p>
                  </div>
                  <div className="space-y-1.5">
                    {agents
                      .filter((a) => a.role === 'agent' || a.role === 'supervisor')
                      .map((agent) => (
                        <div key={agent.id} className="rounded-md border px-3 py-2 text-sm">
                          <div className="flex items-center justify-between">
                            <span className="flex items-center gap-2">
                              <AgentStatusDot status={agent.status} />
                              {agent.name}
                            </span>
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() =>
                                skillEditId === agent.id
                                  ? setSkillEditId(null)
                                  : startEditSkills(agent)
                              }
                            >
                              {skillEditId === agent.id ? 'Cancel' : 'Edit Skills'}
                            </Button>
                          </div>
                          {(agent.product_skills.length > 0 ||
                            agent.regions.length > 0 ||
                            agent.category_skills.length > 0) &&
                            skillEditId !== agent.id && (
                              <div className="mt-1.5 flex flex-wrap gap-1">
                                {agent.category_skills.map((s) => (
                                  <Badge
                                    key={`cat-${s}`}
                                    variant="outline"
                                    className="text-xs text-blue-600"
                                  >
                                    {s}
                                  </Badge>
                                ))}
                                {agent.regions.map((r) => (
                                  <Badge
                                    key={`reg-${r}`}
                                    variant="outline"
                                    className="text-xs text-green-600"
                                  >
                                    {r}
                                  </Badge>
                                ))}
                                {agent.product_skills.map((p) => (
                                  <Badge
                                    key={`prod-${p}`}
                                    variant="outline"
                                    className="text-xs text-purple-600"
                                  >
                                    {p}
                                  </Badge>
                                ))}
                              </div>
                            )}
                          {skillEditId === agent.id && (
                            <div className="mt-2 space-y-2">
                              <div>
                                <label className="mb-0.5 block text-xs text-muted-foreground">
                                  Category Skills (comma-separated)
                                </label>
                                <input
                                  type="text"
                                  value={skillCategoryInput}
                                  onChange={(e) => setSkillCategoryInput(e.target.value)}
                                  placeholder="e.g. Electronics, Fashion, Food"
                                  className="h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
                                />
                              </div>
                              <div>
                                <label className="mb-0.5 block text-xs text-muted-foreground">
                                  Regions (comma-separated)
                                </label>
                                <input
                                  type="text"
                                  value={skillRegionInput}
                                  onChange={(e) => setSkillRegionInput(e.target.value)}
                                  placeholder="e.g. Metro Manila, Cebu, Davao"
                                  className="h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
                                />
                              </div>
                              <div>
                                <label className="mb-0.5 block text-xs text-muted-foreground">
                                  Product Skills (comma-separated)
                                </label>
                                <input
                                  type="text"
                                  value={skillProductInput}
                                  onChange={(e) => setSkillProductInput(e.target.value)}
                                  placeholder="e.g. iPhone, Samsung, Nike"
                                  className="h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
                                />
                              </div>
                              <Button size="sm" onClick={() => saveSkills(agent.id)}>
                                Save Skills
                              </Button>
                            </div>
                          )}
                        </div>
                      ))}
                  </div>
                </div>
              )}

              {can_view_all && (
                <div className="border-t pt-3">
                  <div className="mb-2">
                    <h4 className="text-sm font-medium">Performance Snapshot (30d)</h4>
                    <p className="text-xs text-muted-foreground">
                      Key metrics for each agent over the last 30 days.
                    </p>
                  </div>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="border-b text-left text-xs text-muted-foreground">
                          <th className="py-1.5 pr-3 font-medium">Agent</th>
                          <th className="py-1.5 px-2 font-medium">Perf</th>
                          <th className="py-1.5 px-2 font-medium">Assigned</th>
                          <th className="py-1.5 px-2 font-medium">Resolved</th>
                          <th className="py-1.5 px-2 font-medium">Res. Rate</th>
                          <th className="py-1.5 px-2 font-medium">Avg Response</th>
                          <th className="py-1.5 pl-2 font-medium">Active</th>
                        </tr>
                      </thead>
                      <tbody>
                        {agents
                          .filter((a) => a.role === 'agent' || a.role === 'supervisor')
                          .map((agent) => {
                            const formatDuration = (seconds: number | null) => {
                              if (seconds === null) return '\u2014';
                              if (seconds < 60) return `${seconds}s`;
                              if (seconds < 3600) return `${Math.floor(seconds / 60)}m`;
                              return `${(seconds / 3600).toFixed(1)}h`;
                            };
                            const perfColor =
                              agent.performance_score >= 75
                                ? 'text-green-600'
                                : agent.performance_score >= 50
                                  ? 'text-amber-600'
                                  : 'text-red-600';
                            return (
                              <tr key={agent.id} className="border-b last:border-0">
                                <td className="py-1.5 pr-3">
                                  <span className="flex items-center gap-1.5">
                                    <AgentStatusDot status={agent.status} />
                                    {agent.name}
                                  </span>
                                </td>
                                <td className={`py-1.5 px-2 font-medium ${perfColor}`}>
                                  {Math.round(agent.performance_score)}
                                </td>
                                <td className="py-1.5 px-2 text-muted-foreground">
                                  {agent.total_assigned_30d}
                                </td>
                                <td className="py-1.5 px-2 text-muted-foreground">
                                  {agent.resolved_30d}
                                </td>
                                <td className="py-1.5 px-2">
                                  <span
                                    className={
                                      agent.resolution_rate >= 70
                                        ? 'text-green-600'
                                        : agent.resolution_rate >= 40
                                          ? 'text-amber-600'
                                          : 'text-red-600'
                                    }
                                  >
                                    {agent.resolution_rate}%
                                  </span>
                                </td>
                                <td className="py-1.5 px-2 text-muted-foreground">
                                  {formatDuration(agent.avg_response_seconds_30d)}
                                </td>
                                <td className="py-1.5 pl-2">
                                  <Badge
                                    variant="outline"
                                    className={`text-xs ${
                                      agent.active_conversations >= 15
                                        ? 'border-red-500/30 text-red-600'
                                        : agent.active_conversations >= 8
                                          ? 'border-amber-500/30 text-amber-600'
                                          : ''
                                    }`}
                                  >
                                    {agent.active_conversations}
                                  </Badge>
                                </td>
                              </tr>
                            );
                          })}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}

              {can_view_all && (
                <div className="border-t pt-3">
                  <div className="mb-2">
                    <h4 className="text-sm font-medium">Queue Limits & Overflow</h4>
                    <p className="text-xs text-muted-foreground">
                      Set max active conversations per agent. When at limit, overflow-enabled agents
                      still receive assignments; others are skipped.
                    </p>
                  </div>
                  <div className="space-y-1.5">
                    {agents
                      .filter((a) => a.role === 'agent' || a.role === 'supervisor')
                      .map((agent) => {
                        const atLimit =
                          agent.active_conversations >= agent.max_active_conversations;
                        return (
                          <div key={agent.id} className="rounded-md border px-3 py-2 text-sm">
                            <div className="flex items-center justify-between">
                              <span className="flex items-center gap-2">
                                <AgentStatusDot status={agent.status} />
                                {agent.name}
                                <Badge
                                  variant="outline"
                                  className={`text-xs ${
                                    atLimit ? 'border-red-500/30 text-red-600' : ''
                                  }`}
                                >
                                  {agent.active_conversations}/{agent.max_active_conversations}
                                </Badge>
                                {agent.overflow_enabled && (
                                  <span className="text-[10px] font-medium text-blue-600">
                                    OVERFLOW
                                  </span>
                                )}
                              </span>
                              <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                  queueEditId === agent.id
                                    ? setQueueEditId(null)
                                    : startEditQueue(agent)
                                }
                              >
                                {queueEditId === agent.id ? 'Cancel' : 'Edit Limit'}
                              </Button>
                            </div>
                            {queueEditId === agent.id && (
                              <div className="mt-2 flex items-end gap-3">
                                <div>
                                  <label className="mb-0.5 block text-xs text-muted-foreground">
                                    Max Active
                                  </label>
                                  <input
                                    type="number"
                                    min={1}
                                    max={100}
                                    value={queueMaxInput}
                                    onChange={(e) => setQueueMaxInput(e.target.value)}
                                    className="h-8 w-24 rounded-md border border-input bg-background px-2 text-sm"
                                  />
                                </div>
                                <div className="flex items-center gap-1.5 pb-1.5">
                                  <input
                                    type="checkbox"
                                    id={`overflow-${agent.id}`}
                                    checked={queueOverflowInput}
                                    onChange={(e) => setQueueOverflowInput(e.target.checked)}
                                    className="h-4 w-4 rounded border-input"
                                  />
                                  <label
                                    htmlFor={`overflow-${agent.id}`}
                                    className="text-xs text-muted-foreground"
                                  >
                                    Allow overflow
                                  </label>
                                </div>
                                <Button size="sm" onClick={() => saveQueueLimit(agent.id)}>
                                  Save
                                </Button>
                              </div>
                            )}
                          </div>
                        );
                      })}
                  </div>
                </div>
              )}

              {can_view_all && (
                <div className="border-t pt-3">
                  <div className="mb-2">
                    <h4 className="text-sm font-medium">Shift Schedules</h4>
                    <p className="text-xs text-muted-foreground">
                      Set shift hours per agent. Agents outside their shift are skipped during
                      auto-assignment. Leave blank for 24/7 availability. Supports overnight shifts
                      (e.g. 22:00–06:00).
                    </p>
                  </div>
                  <div className="space-y-1.5">
                    {agents
                      .filter((a) => a.role === 'agent' || a.role === 'supervisor')
                      .map((agent) => {
                        const hasShift = agent.shift_start && agent.shift_end;
                        const now = new Date();
                        const nowMin = now.getHours() * 60 + now.getMinutes();
                        const parseMin = (t: string) => {
                          const [h, m] = t.split(':').map(Number);
                          return h * 60 + m;
                        };
                        let inShift = true;
                        if (hasShift) {
                          const start = parseMin(agent.shift_start!);
                          const end = parseMin(agent.shift_end!);
                          if (end < start) {
                            inShift = nowMin >= start || nowMin < end;
                          } else {
                            inShift = nowMin >= start && nowMin < end;
                          }
                        }
                        return (
                          <div key={agent.id} className="rounded-md border px-3 py-2 text-sm">
                            <div className="flex items-center justify-between">
                              <span className="flex items-center gap-2">
                                <AgentStatusDot status={agent.status} />
                                {agent.name}
                                {hasShift ? (
                                  <Badge
                                    variant="outline"
                                    className={`text-xs ${inShift ? 'border-green-500/30 text-green-600' : 'border-muted text-muted-foreground'}`}
                                  >
                                    {agent.shift_start}–{agent.shift_end}
                                  </Badge>
                                ) : (
                                  <Badge
                                    variant="outline"
                                    className="text-xs text-muted-foreground"
                                  >
                                    24/7
                                  </Badge>
                                )}
                                {hasShift && (
                                  <span
                                    className={`text-[10px] font-medium ${inShift ? 'text-green-600' : 'text-muted-foreground'}`}
                                  >
                                    {inShift ? 'ON SHIFT' : 'OFF SHIFT'}
                                  </span>
                                )}
                              </span>
                              <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                  shiftEditId === agent.id
                                    ? setShiftEditId(null)
                                    : startEditShift(agent)
                                }
                              >
                                {shiftEditId === agent.id ? 'Cancel' : 'Edit Shift'}
                              </Button>
                            </div>
                            {shiftEditId === agent.id && (
                              <div className="mt-2 flex items-end gap-3">
                                <div>
                                  <label className="mb-0.5 block text-xs text-muted-foreground">
                                    Start (HH:MM)
                                  </label>
                                  <input
                                    type="time"
                                    value={shiftStartInput}
                                    onChange={(e) => setShiftStartInput(e.target.value)}
                                    className="h-8 w-28 rounded-md border border-input bg-background px-2 text-sm"
                                  />
                                </div>
                                <div>
                                  <label className="mb-0.5 block text-xs text-muted-foreground">
                                    End (HH:MM)
                                  </label>
                                  <input
                                    type="time"
                                    value={shiftEndInput}
                                    onChange={(e) => setShiftEndInput(e.target.value)}
                                    className="h-8 w-28 rounded-md border border-input bg-background px-2 text-sm"
                                  />
                                </div>
                                <Button size="sm" onClick={() => saveShiftSchedule(agent.id)}>
                                  Save
                                </Button>
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => {
                                    setShiftStartInput('');
                                    setShiftEndInput('');
                                  }}
                                >
                                  Clear
                                </Button>
                              </div>
                            )}
                          </div>
                        );
                      })}
                  </div>
                </div>
              )}

              {can_view_all && (
                <div className="border-t pt-3">
                  <div className="mb-2 flex items-center justify-between">
                    <div>
                      <h4 className="flex items-center gap-1.5 text-sm font-medium">
                        Idle Alerts
                        {agents.some((a) => a.is_idle) && (
                          <Badge variant="destructive" className="text-[10px]">
                            {agents.filter((a) => a.is_idle).length} IDLE
                          </Badge>
                        )}
                      </h4>
                      <p className="text-xs text-muted-foreground">
                        Agents with active conversations who haven&apos;t been seen for longer than
                        their idle threshold.
                      </p>
                    </div>
                  </div>
                  <div className="space-y-1.5">
                    {agents
                      .filter((a) => a.role === 'agent' || a.role === 'supervisor')
                      .map((agent) => (
                        <div key={agent.id} className="rounded-md border px-3 py-2 text-sm">
                          <div className="flex items-center justify-between">
                            <span className="flex items-center gap-2">
                              <AgentStatusDot status={agent.status} />
                              {agent.name}
                              <Badge
                                variant="outline"
                                className={`text-xs ${agent.is_idle ? 'border-red-500/30 text-red-600' : 'text-muted-foreground'}`}
                              >
                                {agent.is_idle
                                  ? 'IDLE'
                                  : `threshold: ${agent.idle_threshold_minutes}m`}
                              </Badge>
                              {agent.is_idle && (
                                <span className="text-[10px] font-medium text-red-600">
                                  {agent.active_conversations} active convo(s) unattended
                                </span>
                              )}
                            </span>
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() =>
                                idleEditId === agent.id ? setIdleEditId(null) : startEditIdle(agent)
                              }
                            >
                              {idleEditId === agent.id ? 'Cancel' : 'Edit Threshold'}
                            </Button>
                          </div>
                          {idleEditId === agent.id && (
                            <div className="mt-2 flex items-end gap-3">
                              <div>
                                <label className="mb-0.5 block text-xs text-muted-foreground">
                                  Idle threshold (minutes)
                                </label>
                                <input
                                  type="number"
                                  min={1}
                                  max={120}
                                  value={idleThresholdInput}
                                  onChange={(e) => setIdleThresholdInput(e.target.value)}
                                  className="h-8 w-32 rounded-md border border-input bg-background px-2 text-sm"
                                />
                              </div>
                              <Button size="sm" onClick={() => saveIdleThreshold(agent.id)}>
                                Save
                              </Button>
                            </div>
                          )}
                        </div>
                      ))}
                  </div>
                </div>
              )}

              {can_view_all && workloadReport && workloadReport.total_agents > 0 && (
                <div className="border-t pt-3">
                  <div className="mb-2">
                    <h4 className="flex items-center gap-1.5 text-sm font-medium">
                      Workload Balancing Report
                      <Badge
                        variant="outline"
                        className={`text-[10px] ${
                          workloadReport.status === 'balanced'
                            ? 'border-green-500/30 text-green-600'
                            : workloadReport.status === 'slightly_imbalanced'
                              ? 'border-yellow-500/30 text-yellow-600'
                              : 'border-red-500/30 text-red-600'
                        }`}
                      >
                        {label(workloadReport.status)}
                      </Badge>
                    </h4>
                    <p className="text-xs text-muted-foreground">
                      {workloadReport.total_active} active conversations across{' '}
                      {workloadReport.total_agents} agent(s). Avg {workloadReport.avg_per_agent} per
                      agent. Range: {workloadReport.min_assigned}–{workloadReport.max_assigned}.
                    </p>
                  </div>

                  <div className="mb-3 space-y-1.5">
                    {workloadReport.distribution.map((d) => (
                      <div key={d.agent_id} className="space-y-0.5">
                        <div className="flex items-center justify-between text-xs">
                          <span className="font-medium">{d.agent_name}</span>
                          <span className="text-muted-foreground">
                            {d.active}/{d.max} ({d.utilization}%)
                          </span>
                        </div>
                        <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                          <div
                            className={`h-full rounded-full ${
                              d.utilization >= 100
                                ? 'bg-red-500'
                                : d.utilization >= 80
                                  ? 'bg-yellow-500'
                                  : 'bg-green-500'
                            }`}
                            style={{ width: `${Math.min(d.utilization, 100)}%` }}
                          />
                        </div>
                      </div>
                    ))}
                  </div>

                  {workloadReport.recommendations.length > 0 && (
                    <div className="rounded-md border border-yellow-500/30 bg-yellow-500/5 p-3">
                      <p className="mb-1.5 text-xs font-medium text-yellow-600">Recommendations</p>
                      <ul className="space-y-1">
                        {workloadReport.recommendations.map((r, i) => (
                          <li key={i} className="text-xs text-muted-foreground">
                            {r.suggestion}
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                </div>
              )}

              {can_view_all && (
                <div className="border-t pt-3">
                  <div className="mb-2">
                    <h4 className="text-sm font-medium">Custom Status Labels</h4>
                    <p className="text-xs text-muted-foreground">
                      Override the default status display text per page. Useful for pages that use
                      different terminology.
                    </p>
                  </div>
                  <div className="space-y-1.5">
                    {Object.entries(statusLabels).map(([pageId, labels]) => {
                      const page = pages.find((p) => p.id === Number(pageId));
                      return Object.entries(labels).map(([status, text]) => (
                        <div
                          key={`${pageId}-${status}`}
                          className="flex items-center gap-2 rounded-md border px-3 py-1.5 text-xs"
                        >
                          <Badge variant="outline">{page?.page_name ?? `Page #${pageId}`}</Badge>
                          <span className="text-muted-foreground">{label(status)}</span>
                          <span className="text-muted-foreground">→</span>
                          <span className="font-medium">{text}</span>
                          <button
                            onClick={() => removeStatusLabel(Number(pageId), status)}
                            className="ml-auto text-muted-foreground hover:text-destructive"
                          >
                            <X className="h-3 w-3" />
                          </button>
                        </div>
                      ));
                    })}
                  </div>
                  <div className="mt-2 flex flex-wrap items-end gap-2">
                    <div className="space-y-1">
                      <label className="text-xs text-muted-foreground">Page</label>
                      <select
                        value={labelPageId}
                        onChange={(e) => setLabelPageId(e.target.value)}
                        className="h-8 rounded-md border border-input bg-background px-2 text-xs"
                      >
                        <option value="">Select page...</option>
                        {pages.map((p) => (
                          <option key={p.id} value={p.id}>
                            {p.page_name}
                          </option>
                        ))}
                      </select>
                    </div>
                    <div className="space-y-1">
                      <label className="text-xs text-muted-foreground">Status</label>
                      <select
                        value={labelStatus}
                        onChange={(e) => setLabelStatus(e.target.value)}
                        className="h-8 rounded-md border border-input bg-background px-2 text-xs"
                      >
                        <option value="">Select status...</option>
                        {statuses.map((s) => (
                          <option key={s} value={s}>
                            {label(s)}
                          </option>
                        ))}
                      </select>
                    </div>
                    <div className="space-y-1">
                      <label className="text-xs text-muted-foreground">Custom Label</label>
                      <Input
                        value={labelText}
                        onChange={(e) => setLabelText(e.target.value)}
                        placeholder="e.g. Pending Payment"
                        className="h-8 w-40 text-xs"
                      />
                    </div>
                    <Button size="sm" variant="outline" onClick={saveStatusLabel}>
                      <Plus className="mr-1 h-3 w-3" />
                      Add Label
                    </Button>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>
        )}

        {showModeration && (
          <Card className="mb-4">
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <MessageCircleWarning className="h-4 w-4" />
                Comment Moderation Queue
              </CardTitle>
              <CardDescription>
                Review and approve or hide incoming page comments before they are visible.
              </CardDescription>
            </CardHeader>
            <CardContent>
              {pending_comments.length === 0 ? (
                <p className="py-4 text-center text-sm text-muted-foreground">
                  No comments pending moderation.
                </p>
              ) : (
                <div className="space-y-2">
                  {pending_comments.map((comment) => (
                    <div
                      key={comment.id}
                      className="flex items-start justify-between gap-3 rounded-md border p-3"
                    >
                      <div className="min-w-0 flex-1 space-y-1">
                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                          <Badge variant="outline">
                            {comment.facebook_page?.page_name ??
                              `Page #${comment.facebook_page_id}`}
                          </Badge>
                          <span>{comment.identity?.display_name ?? 'Unknown user'}</span>
                          {comment.sent_at && <span>· {formatDate(comment.sent_at)}</span>}
                        </div>
                        <p className="text-sm">{comment.body ?? '(no text)'}</p>
                      </div>
                      <div className="flex shrink-0 gap-1">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => moderateComment(comment.id, 'approve')}
                          title="Approve"
                        >
                          <Check className="h-4 w-4 text-green-600" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => moderateComment(comment.id, 'hide')}
                          title="Hide"
                        >
                          <EyeOff className="h-4 w-4 text-red-600" />
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        )}

        {showCanned && (
          <Card className="mb-4">
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <MessagesSquare className="h-4 w-4" />
                Page-Level Canned Response Defaults
              </CardTitle>
              <CardDescription>
                Define default reply templates per Facebook Page. Page-specific templates appear
                first when replying to conversations.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              {page_canned_responses.length > 0 && (
                <div className="space-y-1.5">
                  {page_canned_responses.map((tpl) => (
                    <div
                      key={tpl.id}
                      className="flex items-start justify-between gap-3 rounded-md border p-3"
                    >
                      <div className="min-w-0 flex-1 space-y-1">
                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                          <Badge variant="outline">
                            {tpl.page_name ?? `Page #${tpl.facebook_page_id}`}
                          </Badge>
                          {tpl.category && <Badge variant="secondary">{tpl.category}</Badge>}
                        </div>
                        <p className="text-sm font-medium">{tpl.name}</p>
                        <p className="text-xs text-muted-foreground line-clamp-2">{tpl.message}</p>
                      </div>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => removeCannedResponse(tpl.id)}
                      >
                        <X className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                  ))}
                </div>
              )}
              <div className="space-y-2 border-t pt-3">
                <div className="flex items-end gap-2">
                  <div className="flex-1">
                    <label className="mb-1 block text-xs text-muted-foreground">Page</label>
                    <Select value={cannedPageId} onValueChange={setCannedPageId}>
                      <SelectTrigger className="w-full">
                        <SelectValue placeholder="Select page" />
                      </SelectTrigger>
                      <SelectContent>
                        {pages.map((page) => (
                          <SelectItem key={page.id} value={page.id.toString()}>
                            {page.page_name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="flex-1">
                    <label className="mb-1 block text-xs text-muted-foreground">Name</label>
                    <Input
                      value={cannedName}
                      onChange={(e) => setCannedName(e.target.value)}
                      placeholder="e.g. Welcome Reply"
                      className="h-9"
                    />
                  </div>
                </div>
                <div>
                  <label className="mb-1 block text-xs text-muted-foreground">Message</label>
                  <textarea
                    value={cannedMessage}
                    onChange={(e) => setCannedMessage(e.target.value)}
                    placeholder="Hello po {customer_name}, thank you for messaging {page_name}..."
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm min-h-[60px]"
                  />
                </div>
                <Button
                  size="sm"
                  onClick={addCannedResponse}
                  disabled={!cannedPageId || !cannedName || !cannedMessage}
                >
                  Add Canned Response
                </Button>
              </div>
            </CardContent>
          </Card>
        )}

        <div className="flex flex-wrap items-center gap-1.5">
          <button
            onClick={() => updateFilter({ status: undefined })}
            className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
              !filters.status
                ? 'bg-primary text-primary-foreground'
                : 'bg-muted hover:bg-muted/80 text-muted-foreground'
            }`}
          >
            All
            <span
              className={`text-xs ${!filters.status ? 'text-primary-foreground/70' : 'text-muted-foreground/70'}`}
            >
              {Object.values(statusCounts).reduce((a, b) => a + b, 0)}
            </span>
          </button>
          {statuses.map((status) => {
            const count = statusCounts[status] ?? 0;
            const isActive = filters.status === status;
            return (
              <button
                key={status}
                onClick={() => updateFilter({ status: isActive ? undefined : status })}
                className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                  isActive
                    ? 'bg-primary text-primary-foreground'
                    : 'bg-muted hover:bg-muted/80 text-muted-foreground'
                }`}
              >
                {statusLabel(
                  status,
                  filters.page_id ? Number(filters.page_id) : undefined,
                  statusLabels
                )}
                {count > 0 && (
                  <span
                    className={`text-xs ${isActive ? 'text-primary-foreground/70' : 'text-muted-foreground/70'}`}
                  >
                    {count}
                  </span>
                )}
              </button>
            );
          })}
        </div>

        {conversations.data.length === 0 ? (
          <Card>
            <CardContent className="py-16 text-center text-muted-foreground">
              <Inbox className="mx-auto mb-3 h-10 w-10 opacity-30" />
              <p className="font-medium">No conversations yet</p>
              <p className="text-sm">
                Meta webhook messages will appear here after a Page is connected and subscribed.
              </p>
            </CardContent>
          </Card>
        ) : (
          <>
            {selectedIds.length > 0 && (
              <div className="sticky top-0 z-10 flex flex-wrap items-center gap-2 rounded-lg border bg-background p-3 shadow-md">
                <span className="text-sm font-medium">{selectedIds.length} selected</span>
                <select
                  value={bulkStatus}
                  onChange={(e) => setBulkStatus(e.target.value)}
                  className="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm"
                >
                  {statuses.map((s) => (
                    <option key={s} value={s}>
                      {statusLabel(
                        s,
                        filters.page_id ? Number(filters.page_id) : undefined,
                        statusLabels
                      )}
                    </option>
                  ))}
                </select>
                <Button size="sm" onClick={submitBulk}>
                  <CheckCheck className="mr-1 h-4 w-4" />
                  Apply Status
                </Button>
                <div className="h-5 w-px bg-border" />
                <select
                  value={bulkAgentId}
                  onChange={(e) => setBulkAgentId(e.target.value)}
                  className="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm"
                >
                  <option value="">Assign to...</option>
                  <option value="unassign">Unassign</option>
                  {agents.map((agent) => (
                    <option key={agent.id} value={agent.id.toString()}>
                      {agent.name} ({agent.active_conversations})
                    </option>
                  ))}
                </select>
                <Button size="sm" onClick={submitBulkAssign}>
                  <UserCheck className="mr-1 h-4 w-4" />
                  Assign
                </Button>
                <Button size="sm" variant="outline" onClick={() => setSelectedIds([])}>
                  <X className="h-4 w-4" />
                  Clear
                </Button>
              </div>
            )}

            <div className="grid gap-3">
              <div className="flex items-center gap-2">
                <label className="flex items-center gap-2 text-sm text-muted-foreground">
                  <input
                    type="checkbox"
                    checked={
                      conversations.data.length > 0 &&
                      selectedIds.length === conversations.data.length
                    }
                    onChange={toggleSelectAll}
                    className="h-4 w-4 rounded border-input"
                  />
                  Select all
                </label>
              </div>
              {conversations.data.map((conversation) => (
                <div key={conversation.id} className="relative">
                  <div className="absolute left-3 top-1/2 z-10 -translate-y-1/2">
                    <input
                      type="checkbox"
                      checked={selectedIds.includes(conversation.id)}
                      onChange={() => toggleSelect(conversation.id)}
                      onClick={(e) => e.stopPropagation()}
                      className="h-4 w-4 rounded border-input"
                    />
                  </div>
                  <Link href={`/shop/inbox/${conversation.id}`}>
                    <Card className="transition-colors hover:bg-accent/30 pl-10">
                      <CardHeader className="pb-3">
                        <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                          <div>
                            <CardTitle className="flex items-center gap-2 text-base">
                              <User className="h-4 w-4" />
                              {conversation.customer?.name ??
                                conversation.identity?.display_name ??
                                'Facebook Customer'}
                            </CardTitle>
                            <CardDescription>
                              {conversation.last_message_preview ?? 'No preview available'}
                            </CardDescription>
                          </div>
                          <div className="flex flex-wrap gap-2">
                            <select
                              value={conversation.status}
                              onChange={(e) => {
                                e.stopPropagation();
                                changeStatus(conversation.id, e.target.value);
                              }}
                              onClick={(e) => e.stopPropagation()}
                              className={`h-7 rounded-md border bg-background px-2 text-xs font-medium ${statusBadgeClass(conversation.status)}`}
                            >
                              {statuses.map((s) => {
                                const permitted =
                                  allowedTransitions(conversation.status).includes(s) ||
                                  s === conversation.status;
                                return (
                                  <option key={s} value={s} disabled={!permitted}>
                                    {statusLabel(s, conversation.facebook_page?.id, statusLabels)}
                                    {!permitted ? ' (not allowed)' : ''}
                                  </option>
                                );
                              })}
                            </select>
                            {conversation.sla && conversation.sla.status !== 'none' && (
                              <Badge
                                variant="outline"
                                className={`text-[10px] ${slaBadgeClass(conversation.sla.status)}`}
                                title={`Elapsed: ${formatSlaMinutes(conversation.sla.elapsed_minutes ?? 0)} / ${formatSlaMinutes(conversation.sla.threshold_minutes ?? 0)}`}
                              >
                                {conversation.sla.status === 'breached'
                                  ? `SLA ${formatSlaMinutes(conversation.sla.elapsed_minutes ?? 0)}`
                                  : `${formatSlaMinutes(conversation.sla.remaining_minutes ?? 0)} left`}
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
                            {conversation.is_flagged && (
                              <Badge variant="destructive" className="gap-1">
                                <Flag className="h-3 w-3" />
                                Flagged
                              </Badge>
                            )}
                            {(conversation.tags ?? []).map((tag) => (
                              <Badge
                                key={tag.id}
                                variant="outline"
                                style={{ borderColor: tag.color, color: tag.color }}
                              >
                                {tag.name}
                              </Badge>
                            ))}
                            {conversation.snoozed_until && (
                              <Badge variant="secondary" className="gap-1">
                                Snoozed
                              </Badge>
                            )}
                            {conversation.reminder_at && (
                              <Badge variant="outline" className="gap-1">
                                Reminder
                              </Badge>
                            )}
                            {conversation.sentiment === 'positive' && (
                              <Badge className="gap-1 bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">
                                Positive
                              </Badge>
                            )}
                            {conversation.sentiment === 'negative' && (
                              <Badge className="gap-1 bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300">
                                Negative
                              </Badge>
                            )}
                            {conversation.unread_count > 0 && (
                              <Badge>{conversation.unread_count} unread</Badge>
                            )}
                          </div>
                        </div>
                      </CardHeader>
                      <CardContent className="grid gap-3 text-sm md:grid-cols-5">
                        <div className="flex items-center gap-2 text-muted-foreground">
                          <Store className="h-4 w-4" />
                          {conversation.facebook_page?.page_name ?? 'Unknown Page'}
                        </div>
                        <div className="flex items-center gap-2 text-muted-foreground">
                          <Phone className="h-4 w-4" />
                          {conversation.customer?.normalized_phone ??
                            conversation.identity?.phone_detected ??
                            'No phone detected'}
                        </div>
                        <div className="flex items-center gap-2 text-muted-foreground">
                          <MessageSquare className="h-4 w-4" />
                          {conversation.messages_count} messages
                        </div>
                        <div className="flex items-center gap-2 text-muted-foreground">
                          <UserCheck className="h-4 w-4" />
                          {conversation.assigned_agent?.name ?? 'Unassigned'}
                        </div>
                        <div className="text-muted-foreground">
                          {formatDate(conversation.last_message_at)}
                        </div>
                      </CardContent>
                    </Card>
                  </Link>
                </div>
              ))}
            </div>
          </>
        )}

        {conversations.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: conversations.last_page }, (_, index) => index + 1).map(
              (page) => (
                <Button
                  key={page}
                  size="sm"
                  variant={page === conversations.current_page ? 'default' : 'outline'}
                  onClick={() => router.get('/shop/inbox', { ...filters, page })}
                >
                  {page}
                </Button>
              )
            )}
          </div>
        )}
      </div>
    </AppLayout>
  );
}
