import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
  CheckCheck,
  Flag,
  Inbox,
  MessageSquare,
  Phone,
  Store,
  User,
  UserCheck,
  X,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

interface Page {
  id: number;
  page_id: string;
  page_name: string;
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

interface Props {
  conversations: Paginated<Conversation>;
  pages: Page[];
  agents: { id: number; name: string; role: string }[];
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
  agents,
  statuses,
  priorities = ['low', 'normal', 'high', 'urgent'],
  tags = [],
  filters = {},
}: Props) {
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [bulkStatus, setBulkStatus] = useState<string>('closed');

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
            <select
              value={filters.page_id ?? ''}
              onChange={(event) => updateFilter({ page_id: event.target.value || undefined })}
              className="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
              <option value="">All Pages</option>
              {pages.map((page) => (
                <option key={page.id} value={page.id}>
                  {page.page_name}
                </option>
              ))}
            </select>
            <select
              value={filters.status ?? ''}
              onChange={(event) => updateFilter({ status: event.target.value || undefined })}
              className="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
              <option value="">All Statuses</option>
              {statuses.map((status) => (
                <option key={status} value={status}>
                  {label(status)}
                </option>
              ))}
            </select>
            <select
              value={filters.assigned_agent_id ?? ''}
              onChange={(event) =>
                updateFilter({ assigned_agent_id: event.target.value || undefined })
              }
              className="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
              <option value="">All Agents</option>
              <option value="unassigned">Unassigned</option>
              {agents.map((agent) => (
                <option key={agent.id} value={agent.id}>
                  {agent.name}
                </option>
              ))}
            </select>
            <select
              value={filters.priority ?? ''}
              onChange={(event) => updateFilter({ priority: event.target.value || undefined })}
              className="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
              <option value="">All Priorities</option>
              {priorities.map((p) => (
                <option key={p} value={p}>
                  {label(p)}
                </option>
              ))}
            </select>
            <select
              value={filters.tag_id ?? ''}
              onChange={(event) => updateFilter({ tag_id: event.target.value || undefined })}
              className="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
              <option value="">All Tags</option>
              {tags.map((tag) => (
                <option key={tag.id} value={tag.id}>
                  {tag.name}
                </option>
              ))}
            </select>
            <select
              value={filters.snoozed ?? ''}
              onChange={(event) => updateFilter({ snoozed: event.target.value || undefined })}
              className="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
              <option value="">All Snooze States</option>
              <option value="none">Not Snoozed</option>
              <option value="active">Snoozed (Active)</option>
              <option value="expired">Snoozed (Expired)</option>
            </select>
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
