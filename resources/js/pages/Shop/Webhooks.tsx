import { FormEvent, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
  ArrowLeft,
  Bug,
  CheckCircle2,
  Copy,
  MessageSquare,
  Radio,
  Store,
  XCircle,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

interface FacebookPage {
  id: number;
  page_id: string;
  page_name: string;
  connected_status: string;
  webhook_status: string;
  last_sync_at: string | null;
}

interface WebhookEvent {
  id: number;
  event_id: string | null;
  event_type: string | null;
  sender_psid: string | null;
  recipient_id: string | null;
  signature_valid: boolean;
  processed_at: string | null;
  error_message: string | null;
  created_at: string;
  facebook_page?: { id: number; page_id: string; page_name: string } | null;
}

interface Props {
  stats: {
    events: number;
    processed: number;
    failed: number;
    conversations: number;
  };
  pages: FacebookPage[];
  events: WebhookEvent[];
  callback_url: string;
  verify_token: string;
}

function time(value: string | null) {
  if (!value) return 'Not processed';
  return new Date(value).toLocaleString();
}

export default function ShopWebhooks({ stats, pages, events, callback_url, verify_token }: Props) {
  const [copied, setCopied] = useState<string | null>(null);
  const firstPageId = pages[0]?.id?.toString() ?? '';
  const { data, setData, post, processing, errors } = useForm({
    facebook_page_id: firstPageId,
    sender_psid: '',
    body: 'hello test 09171234567',
  });

  const copy = async (label: string, value: string) => {
    await navigator.clipboard.writeText(value);
    setCopied(label);
    window.setTimeout(() => setCopied(null), 1600);
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post('/shop/webhooks/simulate');
  };

  return (
    <AppLayout>
      <Head title="Shop Webhooks" />

      <div className="space-y-6">
        <div>
          <Button asChild variant="ghost" size="sm" className="-ml-3 mb-2">
            <Link href="/shop">
              <ArrowLeft className="mr-2 h-4 w-4" />
              Shop
            </Link>
          </Button>
          <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
              <h1 className="text-3xl font-bold tracking-tight font-display">Shop Webhooks</h1>
              <p className="text-muted-foreground">
                Meta callback setup, raw events, and inbox ingestion diagnostics
              </p>
            </div>
            <Button variant="outline" onClick={() => router.reload()}>
              <Radio className="mr-2 h-4 w-4" />
              Refresh
            </Button>
          </div>
        </div>

        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm text-muted-foreground">Raw Events</CardTitle>
            </CardHeader>
            <CardContent className="text-2xl font-bold font-display">{stats.events}</CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm text-muted-foreground">Processed</CardTitle>
            </CardHeader>
            <CardContent className="text-2xl font-bold font-display">{stats.processed}</CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm text-muted-foreground">Failed</CardTitle>
            </CardHeader>
            <CardContent className="text-2xl font-bold font-display">{stats.failed}</CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm text-muted-foreground">Conversations</CardTitle>
            </CardHeader>
            <CardContent className="text-2xl font-bold font-display">
              {stats.conversations}
            </CardContent>
          </Card>
        </div>

        <div className="grid gap-6 xl:grid-cols-3">
          <div className="space-y-6 xl:col-span-2">
            <Card>
              <CardHeader>
                <CardTitle>Meta Callback</CardTitle>
                <CardDescription>
                  Use these values in Meta Developers webhook configuration
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-3 text-sm">
                <div className="flex flex-col gap-2 rounded-lg border p-3 md:flex-row md:items-center md:justify-between">
                  <div>
                    <p className="font-medium">Callback URL</p>
                    <p className="break-all text-muted-foreground">{callback_url}</p>
                  </div>
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => copy('callback', callback_url)}
                  >
                    <Copy className="mr-2 h-4 w-4" />
                    {copied === 'callback' ? 'Copied' : 'Copy'}
                  </Button>
                </div>
                <div className="flex flex-col gap-2 rounded-lg border p-3 md:flex-row md:items-center md:justify-between">
                  <div>
                    <p className="font-medium">Verify Token</p>
                    <p className="break-all text-muted-foreground">{verify_token}</p>
                  </div>
                  <Button size="sm" variant="outline" onClick={() => copy('token', verify_token)}>
                    <Copy className="mr-2 h-4 w-4" />
                    {copied === 'token' ? 'Copied' : 'Copy'}
                  </Button>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Recent Webhook Events</CardTitle>
                <CardDescription>Raw Meta events and processing results</CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                {events.length === 0 ? (
                  <div className="py-12 text-center text-muted-foreground">
                    <Bug className="mx-auto mb-3 h-10 w-10 opacity-30" />
                    <p className="font-medium">No webhook events yet</p>
                    <p className="text-sm">
                      Send a customer message to a subscribed Page or use the simulator.
                    </p>
                  </div>
                ) : (
                  events.map((event) => (
                    <div key={event.id} className="rounded-lg border p-3">
                      <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div>
                          <p className="text-sm font-medium">
                            {event.facebook_page?.page_name ?? 'Unknown Page'}
                          </p>
                          <p className="text-xs text-muted-foreground">
                            {event.event_type ?? 'event'} from{' '}
                            {event.sender_psid ?? 'unknown sender'}
                          </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                          <Badge variant={event.processed_at ? 'default' : 'outline'}>
                            {event.processed_at ? 'Processed' : 'Pending'}
                          </Badge>
                          <Badge variant={event.signature_valid ? 'default' : 'secondary'}>
                            {event.signature_valid ? 'Signed' : 'Unsigned'}
                          </Badge>
                          {event.error_message && <Badge variant="destructive">Failed</Badge>}
                        </div>
                      </div>
                      <div className="mt-3 grid gap-2 text-xs text-muted-foreground md:grid-cols-2">
                        <p>Event ID: {event.event_id}</p>
                        <p>Created: {time(event.created_at)}</p>
                        <p>Processed: {time(event.processed_at)}</p>
                        <p>Recipient: {event.recipient_id ?? 'none'}</p>
                      </div>
                      {event.error_message && (
                        <p className="mt-2 text-xs text-destructive">{event.error_message}</p>
                      )}
                    </div>
                  ))
                )}
              </CardContent>
            </Card>
          </div>

          <div className="space-y-6">
            <Card>
              <CardHeader>
                <CardTitle>Simulate Inbound Message</CardTitle>
                <CardDescription>
                  Creates a raw event, processes it, and sends it to the Shop inbox
                </CardDescription>
              </CardHeader>
              <CardContent>
                <form onSubmit={submit} className="space-y-3">
                  <select
                    value={data.facebook_page_id}
                    onChange={(event) => setData('facebook_page_id', event.target.value)}
                    className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                  >
                    {pages.length === 0 ? (
                      <option value="">No connected Pages</option>
                    ) : (
                      pages.map((page) => (
                        <option key={page.id} value={page.id}>
                          {page.page_name}
                        </option>
                      ))
                    )}
                  </select>
                  {errors.facebook_page_id && (
                    <p className="text-xs text-destructive">{errors.facebook_page_id}</p>
                  )}

                  <Input
                    value={data.sender_psid}
                    onChange={(event) => setData('sender_psid', event.target.value)}
                    placeholder="Optional sender PSID"
                  />
                  {errors.sender_psid && (
                    <p className="text-xs text-destructive">{errors.sender_psid}</p>
                  )}

                  <Textarea
                    value={data.body}
                    onChange={(event) => setData('body', event.target.value)}
                    placeholder="Message body"
                  />
                  {errors.body && <p className="text-xs text-destructive">{errors.body}</p>}

                  <Button
                    type="submit"
                    disabled={processing || pages.length === 0}
                    className="w-full"
                  >
                    <MessageSquare className="mr-2 h-4 w-4" />
                    Process Test Message
                  </Button>
                </form>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Connected Pages</CardTitle>
                <CardDescription>Current Page token and webhook status</CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                {pages.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No Pages connected yet.</p>
                ) : (
                  pages.map((page) => (
                    <div key={page.id} className="rounded-lg border p-3">
                      <div className="flex items-start gap-3">
                        <Store className="mt-0.5 h-4 w-4 text-muted-foreground" />
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-medium">{page.page_name}</p>
                          <p className="text-xs text-muted-foreground">{page.page_id}</p>
                          <div className="mt-2 flex flex-wrap gap-2">
                            <Badge variant="outline">{page.connected_status}</Badge>
                            <Badge
                              variant={
                                page.webhook_status === 'subscribed' ? 'default' : 'secondary'
                              }
                            >
                              {page.webhook_status === 'subscribed' ? (
                                <CheckCircle2 className="mr-1 h-3 w-3" />
                              ) : (
                                <XCircle className="mr-1 h-3 w-3" />
                              )}
                              {page.webhook_status}
                            </Badge>
                          </div>
                        </div>
                      </div>
                    </div>
                  ))
                )}
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
