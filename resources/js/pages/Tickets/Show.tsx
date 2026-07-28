import { Head, Link } from '@inertiajs/react';
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
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
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

interface Props {
  ticket: Ticket;
  activityLogs: ActivityLogEntry[];
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

export default function TicketsShow({ ticket, activityLogs }: Props) {
  const statusCfg = statusConfig[ticket.status];
  const priorityCfg = priorityConfig[ticket.priority];

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

            {/* Quick Actions */}
            <Card>
              <CardHeader>
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Quick Actions
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-2">
                <Button variant="outline" size="sm" className="w-full justify-start" disabled>
                  <MessageSquare className="mr-1.5 h-4 w-4" />
                  Reply (coming soon)
                </Button>
                <Button variant="outline" size="sm" className="w-full justify-start" disabled>
                  <CheckCircle className="mr-1.5 h-4 w-4" />
                  Mark Resolved (coming soon)
                </Button>
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
