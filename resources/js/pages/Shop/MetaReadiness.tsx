import { Head, Link, router } from '@inertiajs/react';
import {
  ArrowLeft,
  CheckCircle2,
  Copy,
  ExternalLink,
  PlugZap,
  RefreshCw,
  Radio,
  Store,
  Unplug,
  XCircle,
} from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

interface ReviewItem {
  label: string;
  status: 'ready' | 'needs_action';
  detail: string;
}

interface PageReadiness {
  id: number;
  page_id: string;
  page_name: string;
  connected_status: string;
  webhook_status: string;
  last_sync_at: string | null;
  tasks: string[];
  subscribed_fields: string[];
  subscription_fields: string[];
  subscription_missing_fields: string[];
  subscription_checked_at: string | null;
}

interface RecentEvent {
  id: number;
  event_id: string | null;
  event_type: string | null;
  sender_psid: string | null;
  signature_valid: boolean;
  processed_at: string | null;
  error_message: string | null;
  created_at: string | null;
  facebook_page?: {
    id: number;
    page_id: string;
    page_name: string;
  } | null;
}

interface PermissionJustification {
  scope: string;
  purpose: string;
  usage: string;
  review_evidence: string;
}

interface ScreencastStep {
  title: string;
  detail: string;
  target: string;
}

interface Props {
  config: {
    app_id_configured: boolean;
    app_secret_configured: boolean;
    login_config_id: string | null;
    redirect_uri: string;
    requested_scopes: string[];
    required_webhook_fields: string[];
    callback_url: string;
    verify_token: string;
    privacy_url: string;
    terms_url: string;
    data_deletion_url: string;
    support_email: string | null;
  };
  summary: {
    connected_pages: number;
    subscribed_pages: number;
    pages_needing_retry: number;
    webhook_events: number;
    processed_events: number;
    conversations: number;
  };
  pages: PageReadiness[];
  recent_events: RecentEvent[];
  review_items: ReviewItem[];
  permission_justifications: PermissionJustification[];
  screencast_steps: ScreencastStep[];
  docs: { label: string; url: string }[];
}

function formatTime(value: string | null) {
  if (!value) return 'Not available';

  return new Date(value).toLocaleString();
}

function itemBadge(status: ReviewItem['status']) {
  return status === 'ready'
    ? 'bg-success/10 text-success border-success/20'
    : 'bg-warning/10 text-warning border-warning/20';
}

export default function ShopMetaReadiness({
  config,
  summary,
  pages,
  recent_events,
  review_items,
  permission_justifications,
  screencast_steps,
  docs,
}: Props) {
  const [copied, setCopied] = useState<string | null>(null);

  const copy = async (label: string, value: string) => {
    await navigator.clipboard.writeText(value);
    setCopied(label);
    window.setTimeout(() => setCopied(null), 1600);
  };

  const readyItems = review_items.filter((item) => item.status === 'ready').length;
  const permissionCopy = permission_justifications
    .map(
      (item) =>
        `${item.scope}\nPurpose: ${item.purpose}\nUsage: ${item.usage}\nReview evidence: ${item.review_evidence}`
    )
    .join('\n\n');

  return (
    <AppLayout>
      <Head title="Meta Readiness" />

      <div className="space-y-4">
        <div>
          <Button asChild variant="ghost" size="sm" className="-ml-3 mb-2">
            <Link href="/shop">
              <ArrowLeft className="mr-1.5 h-4 w-4" />
              Shop
            </Link>
          </Button>

          <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
              <h1 className="text-xl font-bold tracking-tight font-display">Meta Readiness</h1>
              <p className="text-muted-foreground">
                Review-critical configuration, webhook state, and demo evidence for Facebook Login
                and Messenger
              </p>
            </div>

            <div className="flex flex-wrap gap-2">
              <Button variant="outline" onClick={() => router.reload()}>
                <RefreshCw className="mr-1.5 h-4 w-4" />
                Refresh
              </Button>
              <Button asChild variant="outline">
                <Link href="/shop/webhooks">
                  <Radio className="mr-1.5 h-4 w-4" />
                  Webhooks
                </Link>
              </Button>
              <Button asChild variant="outline">
                <a href="/shop/facebook/connect">
                  <Store className="mr-1.5 h-4 w-4" />
                  Connect Facebook
                </a>
              </Button>
            </div>
          </div>
        </div>

        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm text-muted-foreground">Checklist</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">
                {readyItems}/{review_items.length}
              </div>
              <p className="mt-1 text-xs text-muted-foreground">Review items currently ready</p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm text-muted-foreground">Connected Pages</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">
                {summary.connected_pages.toLocaleString()}
              </div>
              <p className="mt-1 text-xs text-muted-foreground">Pages synced after OAuth</p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm text-muted-foreground">Subscribed Pages</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">
                {summary.subscribed_pages.toLocaleString()}
              </div>
              <p className="mt-1 text-xs text-muted-foreground">Pages with active webhook fields</p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm text-muted-foreground">Processed Events</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">
                {summary.processed_events.toLocaleString()}
              </div>
              <p className="mt-1 text-xs text-muted-foreground">Webhook events already ingested</p>
            </CardContent>
          </Card>
        </div>

        <div className="grid gap-6 xl:grid-cols-3">
          <div className="space-y-6 xl:col-span-2">
            <Card>
              <CardHeader>
                <CardTitle>App Review Checklist</CardTitle>
                <CardDescription>
                  What is already ready and what still needs action before Meta production review
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                {review_items.map((item) => (
                  <div key={item.label} className="rounded-lg border p-4">
                    <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                      <div>
                        <p className="font-medium">{item.label}</p>
                        <p className="text-sm text-muted-foreground">{item.detail}</p>
                      </div>
                      <Badge variant="outline" className={itemBadge(item.status)}>
                        {item.status === 'ready' ? (
                          <CheckCircle2 className="mr-1 h-3 w-3" />
                        ) : (
                          <XCircle className="mr-1 h-3 w-3" />
                        )}
                        {item.status === 'ready' ? 'Ready' : 'Needs Action'}
                      </Badge>
                    </div>
                  </div>
                ))}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Connected Pages</CardTitle>
                <CardDescription>
                  Page access, webhook state, and the exact subscription fields seen in production
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                {pages.length === 0 ? (
                  <p className="text-sm text-muted-foreground">
                    No Facebook Pages have been connected yet.
                  </p>
                ) : (
                  pages.map((page) => (
                    <div key={page.id} className="rounded-lg border p-4">
                      <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div className="space-y-2">
                          <div>
                            <p className="font-medium">{page.page_name}</p>
                            <p className="text-xs text-muted-foreground">{page.page_id}</p>
                          </div>
                          <div className="flex flex-wrap gap-2">
                            <Badge variant="outline">{page.connected_status}</Badge>
                            <Badge
                              variant={
                                page.webhook_status === 'subscribed' ? 'default' : 'secondary'
                              }
                            >
                              {page.webhook_status}
                            </Badge>
                          </div>
                          <div className="grid gap-1 text-xs text-muted-foreground">
                            <p>Last sync: {formatTime(page.last_sync_at)}</p>
                            <p>Subscription checked: {formatTime(page.subscription_checked_at)}</p>
                          </div>
                        </div>

                        <div className="grid gap-2 text-xs md:min-w-[320px]">
                          <div>
                            <p className="font-medium text-foreground">Page Tasks</p>
                            <div className="mt-1 flex flex-wrap gap-2">
                              {page.tasks.length === 0 ? (
                                <span className="text-muted-foreground">No tasks stored</span>
                              ) : (
                                page.tasks.map((task) => (
                                  <Badge key={task} variant="outline">
                                    {task}
                                  </Badge>
                                ))
                              )}
                            </div>
                          </div>
                          <div>
                            <p className="font-medium text-foreground">Subscribed Fields</p>
                            <div className="mt-1 flex flex-wrap gap-2">
                              {(page.subscription_fields.length > 0
                                ? page.subscription_fields
                                : page.subscribed_fields
                              ).length === 0 ? (
                                <span className="text-muted-foreground">Not confirmed yet</span>
                              ) : (
                                (page.subscription_fields.length > 0
                                  ? page.subscription_fields
                                  : page.subscribed_fields
                                ).map((field) => (
                                  <Badge key={field} variant="outline">
                                    {field}
                                  </Badge>
                                ))
                              )}
                            </div>
                          </div>
                          {page.subscription_missing_fields.length > 0 && (
                            <div>
                              <p className="font-medium text-foreground">Missing Fields</p>
                              <div className="mt-1 flex flex-wrap gap-2">
                                {page.subscription_missing_fields.map((field) => (
                                  <Badge
                                    key={field}
                                    variant="outline"
                                    className="border-warning/20 bg-warning/5 text-warning"
                                  >
                                    {field}
                                  </Badge>
                                ))}
                              </div>
                            </div>
                          )}
                        </div>
                      </div>

                      <div className="mt-3 flex flex-wrap gap-2 border-t pt-3">
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() =>
                            router.post(
                              `/shop/facebook/pages/${page.id}/subscribe`,
                              {},
                              { preserveState: true }
                            )
                          }
                        >
                          <PlugZap className="mr-1.5 h-3.5 w-3.5" />
                          {page.webhook_status === 'subscribed' ? 'Resubscribe' : 'Subscribe'}
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() =>
                            router.post(
                              `/shop/facebook/pages/${page.id}/check`,
                              {},
                              { preserveState: true }
                            )
                          }
                        >
                          <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
                          Check Health
                        </Button>
                        {page.connected_status === 'connected' && (
                          <Button
                            size="sm"
                            variant="outline"
                            className="text-destructive hover:text-destructive"
                            onClick={() => {
                              if (
                                confirm(
                                  `Disconnect ${page.page_name}? You will need to reconnect via Facebook OAuth to restore access.`
                                )
                              ) {
                                router.delete(`/shop/facebook/pages/${page.id}`, {
                                  preserveState: true,
                                });
                              }
                            }}
                          >
                            <Unplug className="mr-1.5 h-3.5 w-3.5" />
                            Disconnect
                          </Button>
                        )}
                      </div>
                    </div>
                  ))
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Recent Webhook Evidence</CardTitle>
                <CardDescription>
                  Recent events that prove the login, subscription, and inbox path is working
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                {recent_events.length === 0 ? (
                  <p className="text-sm text-muted-foreground">
                    No recent webhook events yet. Send one real customer message or run the
                    diagnostic simulator.
                  </p>
                ) : (
                  recent_events.map((event) => (
                    <div key={event.id} className="rounded-lg border p-3">
                      <div className="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
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
                      <div className="mt-2 grid gap-1 text-xs text-muted-foreground">
                        <p>Created: {formatTime(event.created_at)}</p>
                        <p>Processed: {formatTime(event.processed_at)}</p>
                        {event.error_message && (
                          <p className="text-destructive">{event.error_message}</p>
                        )}
                      </div>
                    </div>
                  ))
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                  <div>
                    <CardTitle>Permission Justifications</CardTitle>
                    <CardDescription>
                      Copy-ready explanations for the permissions requested during Meta App Review
                    </CardDescription>
                  </div>
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => copy('permission-copy', permissionCopy)}
                  >
                    <Copy className="mr-1.5 h-4 w-4" />
                    {copied === 'permission-copy' ? 'Copied' : 'Copy All'}
                  </Button>
                </div>
              </CardHeader>
              <CardContent className="space-y-3">
                {permission_justifications.map((item) => (
                  <div key={item.scope} className="rounded-lg border p-4">
                    <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                      <div className="space-y-2">
                        <Badge variant="outline">{item.scope}</Badge>
                        <div className="grid gap-2 text-sm">
                          <div>
                            <p className="font-medium">Purpose</p>
                            <p className="text-muted-foreground">{item.purpose}</p>
                          </div>
                          <div>
                            <p className="font-medium">Usage</p>
                            <p className="text-muted-foreground">{item.usage}</p>
                          </div>
                          <div>
                            <p className="font-medium">Review Evidence</p>
                            <p className="text-muted-foreground">{item.review_evidence}</p>
                          </div>
                        </div>
                      </div>
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                          copy(
                            item.scope,
                            `${item.scope}\nPurpose: ${item.purpose}\nUsage: ${item.usage}\nReview evidence: ${item.review_evidence}`
                          )
                        }
                      >
                        <Copy className="mr-1.5 h-4 w-4" />
                        {copied === item.scope ? 'Copied' : 'Copy'}
                      </Button>
                    </div>
                  </div>
                ))}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Screencast Steps</CardTitle>
                <CardDescription>
                  Use this sequence when recording the Meta App Review demo video
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                {screencast_steps.map((step, index) => (
                  <div key={step.title} className="rounded-lg border p-4">
                    <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                      <div className="flex gap-3">
                        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                          {index + 1}
                        </div>
                        <div>
                          <p className="font-medium">{step.title}</p>
                          <p className="text-sm text-muted-foreground">{step.detail}</p>
                        </div>
                      </div>
                      <Button asChild size="sm" variant="outline">
                        <Link href={step.target}>Open</Link>
                      </Button>
                    </div>
                  </div>
                ))}
              </CardContent>
            </Card>
          </div>

          <div className="space-y-6">
            <Card>
              <CardHeader>
                <CardTitle>Runtime Configuration</CardTitle>
                <CardDescription>
                  Values you need for Meta dashboard setup and App Review
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-3 text-sm">
                <div className="rounded-lg border p-3">
                  <div className="flex items-center justify-between gap-3">
                    <div>
                      <p className="font-medium">App Credentials</p>
                      <p className="text-muted-foreground">
                        App ID: {config.app_id_configured ? 'Configured' : 'Missing'}
                        <br />
                        App Secret: {config.app_secret_configured ? 'Configured' : 'Missing'}
                      </p>
                    </div>
                    <Badge
                      variant="outline"
                      className={
                        config.app_id_configured && config.app_secret_configured
                          ? itemBadge('ready')
                          : itemBadge('needs_action')
                      }
                    >
                      {config.app_id_configured && config.app_secret_configured
                        ? 'Ready'
                        : 'Needs Action'}
                    </Badge>
                  </div>
                </div>

                {[
                  ['Redirect URI', config.redirect_uri, 'redirect'],
                  ['Webhook Callback URL', config.callback_url, 'callback'],
                  ['Webhook Verify Token', config.verify_token, 'token'],
                  ['Privacy Policy URL', config.privacy_url, 'privacy'],
                  ['Terms URL', config.terms_url, 'terms'],
                  ['Data Deletion URL', config.data_deletion_url, 'deletion'],
                ].map(([label, value, key]) => (
                  <div key={key} className="rounded-lg border p-3">
                    <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                      <div>
                        <p className="font-medium">{label}</p>
                        <p className="break-all text-muted-foreground">{value}</p>
                      </div>
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => copy(String(key), String(value))}
                      >
                        <Copy className="mr-1.5 h-4 w-4" />
                        {copied === key ? 'Copied' : 'Copy'}
                      </Button>
                    </div>
                  </div>
                ))}

                <div className="rounded-lg border p-3">
                  <p className="font-medium">Login Config ID</p>
                  <p className="break-all text-muted-foreground">
                    {config.login_config_id || 'Not configured'}
                  </p>
                </div>

                <div className="rounded-lg border p-3">
                  <p className="font-medium">Support Contact</p>
                  <p className="break-all text-muted-foreground">
                    {config.support_email || 'META_SUPPORT_EMAIL is not set'}
                  </p>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Requested Scopes</CardTitle>
                <CardDescription>
                  The OAuth permissions currently requested by the app
                </CardDescription>
              </CardHeader>
              <CardContent className="flex flex-wrap gap-2">
                {config.requested_scopes.map((scope) => (
                  <Badge key={scope} variant="outline">
                    {scope}
                  </Badge>
                ))}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Required Webhook Fields</CardTitle>
                <CardDescription>The Page fields the app expects to be subscribed</CardDescription>
              </CardHeader>
              <CardContent className="flex flex-wrap gap-2">
                {config.required_webhook_fields.map((field) => (
                  <Badge key={field} variant="outline">
                    {field}
                  </Badge>
                ))}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Operational Totals</CardTitle>
                <CardDescription>Evidence that the inbox path is active</CardDescription>
              </CardHeader>
              <CardContent className="space-y-2 text-sm">
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Webhook events</span>
                  <span className="font-medium">{summary.webhook_events.toLocaleString()}</span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Processed events</span>
                  <span className="font-medium">{summary.processed_events.toLocaleString()}</span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Conversations</span>
                  <span className="font-medium">{summary.conversations.toLocaleString()}</span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Pages needing retry</span>
                  <span className="font-medium">
                    {summary.pages_needing_retry.toLocaleString()}
                  </span>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Official Docs</CardTitle>
                <CardDescription>
                  Primary references for production setup and review
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-2">
                {docs.map((doc) => (
                  <a
                    key={doc.url}
                    href={doc.url}
                    target="_blank"
                    rel="noreferrer"
                    className="flex items-center justify-between rounded-lg border p-3 text-sm hover:bg-muted/30"
                  >
                    <span>{doc.label}</span>
                    <ExternalLink className="h-4 w-4 text-muted-foreground" />
                  </a>
                ))}
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
