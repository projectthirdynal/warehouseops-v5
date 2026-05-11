import { FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Send, Store, User } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
  last_message_at: string | null;
  facebook_page?: { id: number; page_name: string; page_id: string; webhook_status: string } | null;
  customer?: { id: number; name: string; phone: string; normalized_phone?: string | null; canonical_address?: string | null } | null;
  identity?: { id: number; display_name?: string | null; provider_user_id: string; phone_detected?: string | null } | null;
  messages: Message[];
}

interface Props {
  conversation: Conversation;
}

function time(value: string | null) {
  if (!value) return '';
  return new Date(value).toLocaleString();
}

export default function ShopConversation({ conversation }: Props) {
  const { data, setData, post, processing, reset, errors } = useForm({ body: '' });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post(`/shop/inbox/${conversation.id}/reply`, {
      preserveScroll: true,
      onSuccess: () => reset('body'),
    });
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
              {conversation.messages.length === 0 ? (
                <p className="py-10 text-center text-sm text-muted-foreground">No messages yet.</p>
              ) : (
                conversation.messages.map((message) => (
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
                    </div>
                  </div>
                ))
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
                  <User className="h-5 w-5" />
                  Customer
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-2 text-sm">
                <p>{conversation.customer?.name ?? 'No matched customer'}</p>
                <p className="text-muted-foreground">{conversation.customer?.canonical_address ?? 'No saved address'}</p>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Store className="h-5 w-5" />
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
