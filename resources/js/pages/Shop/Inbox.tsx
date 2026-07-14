import { useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
  BarChart3,
  CheckCheck,
  Check,
  EyeOff,
  Flag,
  Inbox,
  MessageSquare,
  MessageCircleWarning,
  MessagesSquare,
  Phone,
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

interface Props {
  conversations: Paginated<Conversation>;
  pages: Page[];
  favorite_page_ids?: number[];
  assignment_rules?: AssignmentRule[];
  pending_comments?: PendingComment[];
  page_canned_responses?: PageCannedResponse[];
  agents: { id: number; name: string; role: string }[];
  can_view_all?: boolean;
  current_user_id?: number;
  statuses: string[];
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
}

function formatDate(value: string | null) {
  if (!value) return 'No messages yet';
  return new Date(value).toLocaleString();
}

function label(value: string) {
  return value.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase());
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
  statuses,
  priorities = ['low', 'normal', 'high', 'urgent'],
  tags = [],
  filters = {},
}: Props) {
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [bulkStatus, setBulkStatus] = useState<string>('closed');
  const [pageSearch, setPageSearch] = useState('');
  const [showRules, setShowRules] = useState(false);
  const [showModeration, setShowModeration] = useState(false);
  const [showCanned, setShowCanned] = useState(false);
  const [rulePageId, setRulePageId] = useState('');
  const [ruleAgentId, setRuleAgentId] = useState('');
  const [cannedPageId, setCannedPageId] = useState('');
  const [cannedName, setCannedName] = useState('');
  const [cannedMessage, setCannedMessage] = useState('');

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

  const updateFilter = (next: Record<string, string | undefined>) => {
    router.get('/shop/inbox', { ...filters, ...next }, { preserveState: true });
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
                      {agent.name}
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
                      {label(s)}
                    </option>
                  ))}
                </select>
                <Button size="sm" onClick={submitBulk}>
                  <CheckCheck className="mr-1 h-4 w-4" />
                  Apply
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
