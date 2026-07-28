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
  Timer,
  AlertTriangle,
  CheckCircle2,
  Trash2,
  StickyNote,
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
  due_at?: string | null;
  resolved_at?: string | null;
  sla_status?: 'on_track' | 'warning' | 'overdue' | 'breached' | 'met' | 'none';
  sla_remaining?: { overdue: boolean; hours: number; human: string } | null;
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

interface AssignableUser {
  id: number;
  name: string;
  email?: string;
}

interface CategoryItem {
  id: number;
  name: string;
  slug: string;
  color: string;
  is_active: boolean;
}

interface PriorityItem {
  id: number;
  name: string;
  slug: string;
  color: string;
  level: number;
  is_active: boolean;
}

interface Props {
  ticket: Ticket;
  activityLogs: ActivityLogEntry[];
  comments: Comment[];
  assignableUsers?: AssignableUser[];
  categories?: CategoryItem[];
  priorities?: PriorityItem[];
  currentUserId?: number;
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

const priorityColorMap: Record<string, string> = {
  gray: 'text-muted-foreground',
  amber: 'text-amber-600',
  orange: 'text-orange-600',
  red: 'text-destructive',
};

const slaConfig: Record<
  string,
  { label: string; className: string; icon: 'timer' | 'alert' | 'check' }
> = {
  on_track: {
    label: 'On Track',
    className: 'text-success border-success/30 bg-success/5',
    icon: 'timer',
  },
  warning: {
    label: 'Warning',
    className: 'text-amber-600 border-amber-300 bg-amber-50',
    icon: 'timer',
  },
  overdue: {
    label: 'Overdue',
    className: 'text-destructive border-destructive/30 bg-destructive/5',
    icon: 'alert',
  },
  breached: {
    label: 'SLA Breached',
    className: 'text-destructive border-destructive/30 bg-destructive/5',
    icon: 'alert',
  },
  met: {
    label: 'SLA Met',
    className: 'text-success border-success/30 bg-success/5',
    icon: 'check',
  },
  none: { label: '', className: '', icon: 'timer' },
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

export default function TicketsShow({
  ticket,
  activityLogs,
  comments,
  assignableUsers,
  categories,
  priorities,
  currentUserId,
}: Props) {
  const statusCfg = statusConfig[ticket.status];
  const priItem = priorities?.find((p) => p.slug === ticket.priority);
  const priColor = priItem
    ? priorityColorMap[priItem.color] || 'text-muted-foreground'
    : 'text-muted-foreground';
  const priLabel = priItem ? priItem.name : ticket.priority;
  const catItem = categories?.find((c) => c.slug === ticket.category);
  const catLabel = catItem ? catItem.name : ticket.category;
  const [commentTab, setCommentTab] = useState<'public' | 'internal'>('public');
  const [statusUpdating, setStatusUpdating] = useState(false);
  const [assignUpdating, setAssignUpdating] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const allowedTransitions: Record<Ticket['status'], Ticket['status'][]> = {
    open: ['in_progress', 'waiting', 'resolved', 'closed'],
    in_progress: ['waiting', 'resolved', 'closed', 'open'],
    waiting: ['in_progress', 'resolved', 'closed', 'open'],
    resolved: ['closed', 'in_progress', 'open'],
    closed: ['in_progress', 'open'],
  };

  const publicForm = useForm({
    body: '',
    is_internal: false,
  });
  const internalForm = useForm({
    body: '',
    is_internal: true,
  });

  function submitPublicComment(e: React.FormEvent) {
    e.preventDefault();
    publicForm.post(`/tickets/${ticket.id}/comments`, {
      preserveScroll: true,
      onSuccess: () => {
        publicForm.reset();
        toast.success('Comment added.');
      },
      onError: () => toast.error('Failed to add comment.'),
    });
  }

  function submitInternalNote(e: React.FormEvent) {
    e.preventDefault();
    internalForm.post(`/tickets/${ticket.id}/comments`, {
      preserveScroll: true,
      onSuccess: () => {
        internalForm.reset();
        toast.success('Internal note added.');
      },
      onError: () => toast.error('Failed to add internal note.'),
    });
  }

  function deleteComment(commentId: number, isInternal: boolean) {
    setDeletingId(commentId);
    router.delete(`/tickets/${ticket.id}/comments/${commentId}`, {
      preserveScroll: true,
      onSuccess: () => {
        toast.success(isInternal ? 'Internal note deleted.' : 'Comment deleted.');
      },
      onError: () => toast.error('Failed to delete.'),
      onFinish: () => setDeletingId(null),
    });
  }

  const publicComments = comments.filter((c) => !c.is_internal);
  const internalNotes = comments.filter((c) => c.is_internal);
  const activeComments = commentTab === 'public' ? publicComments : internalNotes;

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

  function handleAssign(userId: number) {
    setAssignUpdating(true);
    router.patch(
      `/tickets/${ticket.id}/assign`,
      { assigned_to: userId },
      {
        preserveScroll: true,
        onSuccess: () => toast.success('Ticket assigned successfully.'),
        onError: () => toast.error('Failed to assign ticket.'),
        onFinish: () => setAssignUpdating(false),
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
                  priItem?.color === 'red'
                    ? 'bg-destructive/10'
                    : priItem?.color === 'orange'
                      ? 'bg-orange-100'
                      : priItem?.color === 'amber'
                        ? 'bg-amber-100'
                        : 'bg-muted'
                }`}
              >
                <Headphones className={`h-6 w-6 ${priColor}`} />
              </div>

              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="font-mono text-sm text-muted-foreground">
                    #{ticket.ticket_number}
                  </span>
                  <Badge variant={statusCfg.variant}>{statusCfg.label}</Badge>
                  <Badge variant="outline" className={priColor}>
                    {priLabel}
                  </Badge>
                  <Badge variant="outline">
                    <Tag className="mr-1 h-3 w-3" />
                    {catLabel}
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

            {/* Comments & Internal Notes */}
            <Card>
              <CardHeader>
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => setCommentTab('public')}
                    className={`flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                      commentTab === 'public'
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:bg-muted'
                    }`}
                  >
                    <MessageSquare className="h-3.5 w-3.5" />
                    Comments
                    <Badge
                      variant={commentTab === 'public' ? 'secondary' : 'outline'}
                      className="ml-1 text-xs"
                    >
                      {publicComments.length}
                    </Badge>
                  </button>
                  <button
                    onClick={() => setCommentTab('internal')}
                    className={`flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                      commentTab === 'internal'
                        ? 'bg-amber-600 text-white'
                        : 'text-muted-foreground hover:bg-muted'
                    }`}
                  >
                    <Lock className="h-3.5 w-3.5" />
                    Internal Notes
                    <Badge
                      variant={commentTab === 'internal' ? 'secondary' : 'outline'}
                      className="ml-1 text-xs"
                    >
                      {internalNotes.length}
                    </Badge>
                  </button>
                </div>
              </CardHeader>
              <CardContent className="space-y-4">
                {activeComments.length > 0 ? (
                  <div className="space-y-3">
                    {activeComments.map((comment) => (
                      <div
                        key={comment.id}
                        className={`flex items-start gap-3 rounded-lg p-3 ${
                          comment.is_internal ? 'bg-amber-50 border border-amber-200' : ''
                        }`}
                      >
                        <Avatar className="h-8 w-8 mt-0.5">
                          <AvatarFallback className="text-xs">
                            {getInitials(comment.user.name)}
                          </AvatarFallback>
                        </Avatar>
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2">
                            <span className="text-sm font-medium">{comment.user.name}</span>
                            {comment.is_internal && (
                              <Badge
                                variant="secondary"
                                className="text-xs bg-amber-200 text-amber-900"
                              >
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
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-7 w-7 text-muted-foreground hover:text-destructive"
                          disabled={deletingId === comment.id}
                          onClick={() => deleteComment(comment.id, comment.is_internal)}
                        >
                          <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="text-sm text-muted-foreground italic">
                    {commentTab === 'public' ? 'No comments yet.' : 'No internal notes yet.'}
                  </p>
                )}

                {/* Public Comment Form */}
                {commentTab === 'public' && (
                  <form onSubmit={submitPublicComment} className="space-y-3 pt-3 border-t">
                    <Textarea
                      value={publicForm.data.body}
                      onChange={(e) => publicForm.setData('body', e.target.value)}
                      placeholder="Write a public reply..."
                      rows={3}
                      required
                    />
                    <div className="flex justify-end">
                      <Button
                        type="submit"
                        size="sm"
                        disabled={publicForm.processing || !publicForm.data.body.trim()}
                      >
                        {publicForm.processing ? (
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
                )}

                {/* Internal Note Form */}
                {commentTab === 'internal' && (
                  <form onSubmit={submitInternalNote} className="space-y-3 pt-3 border-t">
                    <Textarea
                      value={internalForm.data.body}
                      onChange={(e) => internalForm.setData('body', e.target.value)}
                      placeholder="Write an internal note (visible to staff only)..."
                      rows={3}
                      required
                      className="border-amber-300 bg-amber-50/30"
                    />
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-1.5 text-xs text-amber-700">
                        <Lock className="h-3 w-3" />
                        Internal notes are only visible to staff members.
                      </div>
                      <Button
                        type="submit"
                        size="sm"
                        disabled={internalForm.processing || !internalForm.data.body.trim()}
                        className="bg-amber-600 hover:bg-amber-700"
                      >
                        {internalForm.processing ? (
                          'Saving...'
                        ) : (
                          <>
                            <StickyNote className="mr-1.5 h-3.5 w-3.5" />
                            Add Note
                          </>
                        )}
                      </Button>
                    </div>
                  </form>
                )}
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
                  {assignableUsers && assignableUsers.length > 0 && (
                    <div className="mt-3 space-y-2">
                      <Select
                        value={ticket.assigned_to ? String(ticket.assigned_to.id) : ''}
                        onValueChange={(v) => handleAssign(Number(v))}
                        disabled={assignUpdating}
                      >
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder="Select assignee..." />
                        </SelectTrigger>
                        <SelectContent>
                          {assignableUsers.map((user) => (
                            <SelectItem key={user.id} value={String(user.id)}>
                              {user.name}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <Button
                        variant="outline"
                        size="sm"
                        className="w-full justify-start"
                        disabled={assignUpdating || !currentUserId}
                        onClick={() => currentUserId && handleAssign(currentUserId)}
                      >
                        <User className="mr-1.5 h-4 w-4" />
                        Assign to Me
                      </Button>
                    </div>
                  )}
                </div>
              </CardContent>
            </Card>

            {/* SLA Tracking */}
            {ticket.sla_status && ticket.sla_status !== 'none' && (
              <Card>
                <CardHeader>
                  <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                    <Timer className="h-4 w-4" />
                    SLA Tracking
                  </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                  <div
                    className={`rounded-lg border p-3 ${slaConfig[ticket.sla_status]?.className ?? ''}`}
                  >
                    <div className="flex items-center gap-2">
                      {ticket.sla_status === 'overdue' || ticket.sla_status === 'breached' ? (
                        <AlertTriangle className="h-5 w-5" />
                      ) : ticket.sla_status === 'met' ? (
                        <CheckCircle2 className="h-5 w-5" />
                      ) : (
                        <Timer className="h-5 w-5" />
                      )}
                      <div>
                        <div className="text-sm font-semibold">
                          {slaConfig[ticket.sla_status]?.label}
                        </div>
                        {ticket.sla_remaining && (
                          <div className="text-xs">{ticket.sla_remaining.human}</div>
                        )}
                      </div>
                    </div>
                  </div>
                  {ticket.due_at && (
                    <div className="text-xs text-muted-foreground">
                      <div>Due: {formatDate(ticket.due_at)}</div>
                      {ticket.resolved_at && <div>Resolved: {formatDate(ticket.resolved_at)}</div>}
                    </div>
                  )}
                </CardContent>
              </Card>
            )}

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
