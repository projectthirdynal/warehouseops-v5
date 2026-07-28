import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
  ArrowLeft,
  Headphones,
  User,
  Clock,
  MessageSquare,
  CheckCircle,
  AlertCircle,
  Tag,
  Package,
  Activity,
  Send,
  Lock,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { formatDate } from '@/lib/utils';

interface TicketUser {
  id: number;
  name: string;
  email?: string;
}

interface Ticket {
  id: number;
  ticket_number: string;
  subject: string;
  description: string;
  status: 'open' | 'in_progress' | 'waiting' | 'resolved' | 'closed';
  priority: 'low' | 'medium' | 'high' | 'urgent';
  category: string;
  created_by: TicketUser;
  assigned_to?: TicketUser | null;
  related_waybill?: string;
  related_lead?: number;
  created_at: string;
  updated_at: string;
}

interface ActivityLogEntry {
  id: number;
  action: string;
  user?: { id: number; name: string };
  metadata?: Record<string, unknown>;
  created_at: string;
}

interface Comment {
  id: number;
  body: string;
  is_internal: boolean;
  user: { id: number; name: string };
  created_at: string;
}

interface Props {
  ticket: Ticket;
  activityLogs: ActivityLogEntry[];
  comments: Comment[];
}

const statusConfig: Record<
  Ticket['status'],
  { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }
> = {
  open: { label: 'Open', variant: 'destructive' },
  in_progress: { label: 'In Progress', variant: 'default' },
  waiting: { label: 'Waiting', variant: 'secondary' },
  resolved: { label: 'Resolved', variant: 'outline' },
  closed: { label: 'Closed', variant: 'outline' },
};

const priorityConfig: Record<Ticket['priority'], { label: string; color: string }> = {
  low: { label: 'Low', color: 'text-muted-foreground' },
  medium: { label: 'Medium', color: 'text-warning' },
  high: { label: 'High', color: 'text-warning' },
  urgent: { label: 'Urgent', color: 'text-destructive' },
};

function getInitials(name: string): string {
  return name
    .split(' ')
    .map((n) => n[0])
    .slice(0, 2)
    .join('')
    .toUpperCase();
}

function formatActionLabel(action: string): string {
  return action
    .split('_')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
}

export default function TicketsShow({ ticket, activityLogs, comments }: Props) {
  const statusCfg = statusConfig[ticket.status];
  const priorityCfg = priorityConfig[ticket.priority];
  const [showInternal, setShowInternal] = useState(true);
  const [statusUpdating, setStatusUpdating] = useState(false);

  const allowedTransitions: Record<Ticket['status'], Ticket['status'][]> = {
    open: ['in_progress', 'waiting', 'resolved', 'closed'],
    in_progress: ['waiting', 'resolved', 'closed', 'open'],
    waiting: ['in_progress', 'resolved', 'closed', 'open'],
    resolved: ['closed', 'in_progress', 'open'],
    closed: ['in_progress', 'open'],
  };

  const form = useForm({
    body: '',
    is_internal: false,
  });

  function submitComment(e: React.FormEvent) {
    e.preventDefault();
    form.post(`/tickets/${ticket.id}/comments`, {
      preserveScroll: true,
      onSuccess: () => {
        form.reset();
        toast.success('Comment added.');
      },
      onError: () => toast.error('Failed to add comment.'),
    });
  }

  const visibleComments = showInternal ? comments : comments.filter((c) => !c.is_internal);

  function handleStatusChange(newStatus: Ticket['status']) {
    setStatusUpdating(true);
    router.patch(
      `/tickets/${ticket.id}/status`,
      { status: newStatus },
      {
        preserveScroll: true,
        onSuccess: () => toast.success(`Status changed to ${statusConfig[newStatus].label}.`),
        onError: () => toast.error('Failed to update status.'),
        onFinish: () => setStatusUpdating(false),
      }
    );
  }

  return (
    <AppLayout>
      <Head title={`Ticket ${ticket.ticket_number}`} />

      <div className="space-y-6">
        {/* Back button */}
        <div>
          <Button variant="ghost" size="sm" asChild>
            <Link href="/tickets">
              <ArrowLeft className="mr-1.5 h-4 w-4" />
              Back to Tickets
            </Link>
          </Button>
        </div>

        {/* Ticket Header */}
        <Card>
          <CardContent className="p-6">
            <div className="flex items-start gap-4">
              <div
                className={`p-3 rounded-full ${
                  ticket.priority === 'urgent'
                    ? 'bg-destructive/10'
                    : ticket.priority === 'high'
                      ? 'bg-warning/10'
                      : 'bg-muted'
                }`}
              >
                <Headphones className={`h-6 w-6 ${priorityCfg.color}`} />
              </div>

              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="font-mono text-sm text-muted-foreground">
                    #{ticket.ticket_number}
                  </span>
                  <Badge variant={statusCfg.variant}>{statusCfg.label}</Badge>
                  <Badge variant="outline" className={priorityCfg.color}>
                    {priorityCfg.label}
                  </Badge>
                  <Badge variant="outline">
                    <Tag className="mr-1 h-3 w-3" />
                    {ticket.category}
                  </Badge>
                </div>

                <h1 className="mt-2 text-xl font-bold font-display tracking-tight">
                  {ticket.subject}
                </h1>

                <div className="mt-3 flex items-center gap-4 text-sm text-muted-foreground">
                  <div className="flex items-center gap-1">
                    <User className="h-3 w-3" />
                    Created by {ticket.created_by.name}
                  </div>
                  <div className="flex items-center gap-1">
                    <Clock className="h-3 w-3" />
                    {formatDate(ticket.created_at)}
                  </div>
                  {ticket.updated_at !== ticket.created_at && (
                    <div className="flex items-center gap-1">
                      <Activity className="h-3 w-3" />
                      Updated {formatDate(ticket.updated_at)}
                    </div>
                  )}
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <div className="grid gap-6 md:grid-cols-3">
          {/* Main Content */}
          <div className="md:col-span-2 space-y-6">
            {/* Description */}
            <Card>
              <CardHeader>
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Description
                </CardTitle>
              </CardHeader>
              <CardContent>
                {ticket.description ? (
                  <p className="text-sm whitespace-pre-wrap">{ticket.description}</p>
                ) : (
                  <p className="text-sm text-muted-foreground italic">No description provided.</p>
                )}
              </CardContent>
            </Card>

            {/* Comments Thread */}
            <Card>
              <CardHeader>
                <div className="flex items-center justify-between">
                  <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                    <MessageSquare className="h-4 w-4" />
                    Comments ({comments.length})
                  </CardTitle>
                  {comments.some((c) => c.is_internal) && (
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => setShowInternal(!showInternal)}
                    >
                      <Lock className="mr-1.5 h-3 w-3" />
                      {showInternal ? 'Hide Internal' : 'Show Internal'}
                    </Button>
                  )}
                </div>
              </CardHeader>
              <CardContent className="space-y-4">
                {visibleComments.length > 0 ? (
                  <div className="space-y-3">
                    {visibleComments.map((comment) => (
                      <div key={comment.id} className="flex items-start gap-3">
                        <Avatar className="h-8 w-8 mt-0.5">
                          <AvatarFallback className="text-xs">
                            {getInitials(comment.user.name)}
                          </AvatarFallback>
                        </Avatar>
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2">
                            <span className="text-sm font-medium">{comment.user.name}</span>
                            {comment.is_internal && (
                              <Badge variant="secondary" className="text-xs">
                                <Lock className="mr-1 h-2.5 w-2.5" />
                                Internal
                              </Badge>
                            )}
                            <span className="text-xs text-muted-foreground">
                              {formatDate(comment.created_at)}
                            </span>
                          </div>
                          <p className="mt-1 text-sm whitespace-pre-wrap">{comment.body}</p>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="text-sm text-muted-foreground italic">No comments yet.</p>
                )}

                {/* Reply Form */}
                <form onSubmit={submitComment} className="space-y-3 pt-3 border-t">
                  <Textarea
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                    placeholder="Write a reply..."
                    rows={3}
                    required
                  />
                  <div className="flex items-center justify-between">
                    <label className="flex items-center gap-2 text-sm text-muted-foreground cursor-pointer">
                      <input
                        type="checkbox"
                        checked={form.data.is_internal}
                        onChange={(e) => form.setData('is_internal', e.target.checked)}
                        className="rounded border-input"
                      />
                      <Lock className="h-3 w-3" />
                      Internal note
                    </label>
                    <Button
                      type="submit"
                      size="sm"
                      disabled={form.processing || !form.data.body.trim()}
                    >
                      {form.processing ? (
                        'Sending...'
                      ) : (
                        <>
                          <Send className="mr-1.5 h-3.5 w-3.5" />
                          Post Comment
                        </>
                      )}
                    </Button>
                  </div>
                </form>
              </CardContent>
            </Card>

            {/* Activity Log */}
            <Card>
              <CardHeader>
                <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                  <Activity className="h-4 w-4" />
                  Activity Log
                </CardTitle>
              </CardHeader>
              <CardContent>
                {activityLogs.length > 0 ? (
                  <div className="space-y-3">
                    {activityLogs.map((log) => (
                      <div
                        key={log.id}
                        className="flex items-start gap-3 pb-3 border-b last:border-0 last:pb-0"
                      >
                        <div className="mt-0.5">
                          <div className="h-2 w-2 rounded-full bg-primary" />
                        </div>
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2">
                            <span className="text-sm font-medium">
                              {formatActionLabel(log.action)}
                            </span>
                            <span className="text-xs text-muted-foreground">
                              by {log.user?.name ?? 'System'}
                            </span>
                          </div>
                          <span className="text-xs text-muted-foreground">
                            {formatDate(log.created_at)}
                          </span>
                          {log.metadata && Object.keys(log.metadata).length > 0 && (
                            <pre className="mt-1 text-xs text-muted-foreground bg-muted rounded p-2 overflow-x-auto">
                              {JSON.stringify(log.metadata, null, 2)}
                            </pre>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="text-sm text-muted-foreground italic">No activity recorded yet.</p>
                )}
              </CardContent>
            </Card>
          </div>

          {/* Sidebar */}
          <div className="space-y-6">
            {/* Assignment */}
            <Card>
              <CardHeader>
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Assignment
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <div>
                  <div className="text-xs text-muted-foreground mb-1">Created By</div>
                  <div className="flex items-center gap-2">
                    <Avatar className="h-7 w-7">
                      <AvatarFallback className="text-xs">
                        {getInitials(ticket.created_by.name)}
                      </AvatarFallback>
                    </Avatar>
                    <div>
                      <div className="text-sm font-medium">{ticket.created_by.name}</div>
                      {ticket.created_by.email && (
                        <div className="text-xs text-muted-foreground">
                          {ticket.created_by.email}
                        </div>
                      )}
                    </div>
                  </div>
                </div>

                <div>
                  <div className="text-xs text-muted-foreground mb-1">Assigned To</div>
                  {ticket.assigned_to ? (
                    <div className="flex items-center gap-2">
                      <Avatar className="h-7 w-7">
                        <AvatarFallback className="text-xs">
                          {getInitials(ticket.assigned_to.name)}
                        </AvatarFallback>
                      </Avatar>
                      <div>
                        <div className="text-sm font-medium">{ticket.assigned_to.name}</div>
                        {ticket.assigned_to.email && (
                          <div className="text-xs text-muted-foreground">
                            {ticket.assigned_to.email}
                          </div>
                        )}
                      </div>
                    </div>
                  ) : (
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                      <AlertCircle className="h-4 w-4" />
                      Unassigned
                    </div>
                  )}
                </div>
              </CardContent>
            </Card>

            {/* Related Items */}
            <Card>
              <CardHeader>
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Related Items
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-2">
                {ticket.related_waybill ? (
                  <div className="flex items-center gap-2 text-sm">
                    <Package className="h-4 w-4 text-muted-foreground" />
                    <span className="text-muted-foreground">Waybill:</span>
                    <Badge variant="outline">{ticket.related_waybill}</Badge>
                  </div>
                ) : (
                  <p className="text-sm text-muted-foreground italic">No related items.</p>
                )}
              </CardContent>
            </Card>

            {/* Status Management */}
            <Card>
              <CardHeader>
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Status Management
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="space-y-1.5">
                  <label className="text-xs text-muted-foreground">Current Status</label>
                  <Select
                    value={ticket.status}
                    onValueChange={(v) => handleStatusChange(v as Ticket['status'])}
                    disabled={statusUpdating}
                  >
                    <SelectTrigger className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {allowedTransitions[ticket.status].map((s) => (
                        <SelectItem key={s} value={s}>
                          {statusConfig[s].label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                {ticket.status !== 'resolved' && ticket.status !== 'closed' && (
                  <Button
                    variant="outline"
                    size="sm"
                    className="w-full justify-start"
                    disabled={statusUpdating}
                    onClick={() => handleStatusChange('resolved')}
                  >
                    <CheckCircle className="mr-1.5 h-4 w-4" />
                    Mark Resolved
                  </Button>
                )}
                {ticket.status === 'resolved' && (
                  <Button
                    variant="outline"
                    size="sm"
                    className="w-full justify-start"
                    disabled={statusUpdating}
                    onClick={() => handleStatusChange('closed')}
                  >
                    <CheckCircle className="mr-1.5 h-4 w-4" />
                    Close Ticket
                  </Button>
                )}
              </CardContent>
            </Card>

            {/* Quick Actions */}
            <Card>
              <CardHeader>
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Quick Actions
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-2">
                <Button
                  variant="outline"
                  size="sm"
                  className="w-full justify-start"
                  onClick={() => document.querySelector('textarea')?.focus()}
                >
                  <MessageSquare className="mr-1.5 h-4 w-4" />
                  Reply
                </Button>
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
