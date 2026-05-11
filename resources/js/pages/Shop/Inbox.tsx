import { Head, Link, router } from '@inertiajs/react';
import { Inbox, MessageSquare, Phone, Store, User } from 'lucide-react';
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
  last_message_preview: string | null;
  last_message_at: string | null;
  unread_count: number;
  messages_count: number;
  facebook_page?: Page | null;
  customer?: { id: number; name: string; phone: string; normalized_phone?: string | null } | null;
  identity?: { id: number; display_name?: string | null; phone_detected?: string | null } | null;
}

interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
}

interface Props {
  conversations: Paginated<Conversation>;
  pages: Page[];
  filters: {
    page_id?: string;
    status?: string;
  };
}

function formatDate(value: string | null) {
  if (!value) return 'No messages yet';
  return new Date(value).toLocaleString();
}

export default function ShopInbox({ conversations, pages, filters }: Props) {
  const updateFilter = (next: Record<string, string | undefined>) => {
    router.get('/shop/inbox', { ...filters, ...next }, { preserveState: true });
  };

  return (
    <AppLayout>
      <Head title="Shop Inbox" />

      <div className="space-y-6">
        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
          <div>
            <h1 className="text-3xl font-bold tracking-tight">Shop Inbox</h1>
            <p className="text-muted-foreground">Messenger conversations from connected Facebook Pages</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <select
              value={filters.page_id ?? ''}
              onChange={(event) => updateFilter({ page_id: event.target.value || undefined })}
              className="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
              <option value="">All Pages</option>
              {pages.map((page) => (
                <option key={page.id} value={page.id}>{page.page_name}</option>
              ))}
            </select>
            <select
              value={filters.status ?? ''}
              onChange={(event) => updateFilter({ status: event.target.value || undefined })}
              className="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
              <option value="">All Statuses</option>
              <option value="open">Open</option>
              <option value="closed">Closed</option>
            </select>
          </div>
        </div>

        {conversations.data.length === 0 ? (
          <Card>
            <CardContent className="py-16 text-center text-muted-foreground">
              <Inbox className="mx-auto mb-3 h-10 w-10 opacity-30" />
              <p className="font-medium">No conversations yet</p>
              <p className="text-sm">Meta webhook messages will appear here after a Page is connected and subscribed.</p>
            </CardContent>
          </Card>
        ) : (
          <div className="grid gap-3">
            {conversations.data.map((conversation) => (
              <Link key={conversation.id} href={`/shop/inbox/${conversation.id}`}>
                <Card className="transition-colors hover:bg-accent/30">
                <CardHeader className="pb-3">
                  <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                      <CardTitle className="flex items-center gap-2 text-base">
                        <User className="h-4 w-4" />
                        {conversation.customer?.name ?? conversation.identity?.display_name ?? 'Facebook Customer'}
                      </CardTitle>
                      <CardDescription>{conversation.last_message_preview ?? 'No preview available'}</CardDescription>
                    </div>
                    <div className="flex flex-wrap gap-2">
                      <Badge variant="outline">{conversation.status}</Badge>
                      {conversation.unread_count > 0 && <Badge>{conversation.unread_count} unread</Badge>}
                    </div>
                  </div>
                </CardHeader>
                <CardContent className="grid gap-3 text-sm md:grid-cols-4">
                  <div className="flex items-center gap-2 text-muted-foreground">
                    <Store className="h-4 w-4" />
                    {conversation.facebook_page?.page_name ?? 'Unknown Page'}
                  </div>
                  <div className="flex items-center gap-2 text-muted-foreground">
                    <Phone className="h-4 w-4" />
                    {conversation.customer?.normalized_phone ?? conversation.identity?.phone_detected ?? 'No phone detected'}
                  </div>
                  <div className="flex items-center gap-2 text-muted-foreground">
                    <MessageSquare className="h-4 w-4" />
                    {conversation.messages_count} messages
                  </div>
                  <div className="text-muted-foreground">{formatDate(conversation.last_message_at)}</div>
                </CardContent>
              </Card>
              </Link>
            ))}
          </div>
        )}

        {conversations.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: conversations.last_page }, (_, index) => index + 1).map((page) => (
              <Button
                key={page}
                size="sm"
                variant={page === conversations.current_page ? 'default' : 'outline'}
                onClick={() => router.get('/shop/inbox', { ...filters, page })}
              >
                {page}
              </Button>
            ))}
          </div>
        )}
      </div>
    </AppLayout>
  );
}
