import AppLayout from '@/layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  MessageSquare,
  Plus,
  Send,
  CheckCircle,
  XCircle,
  Clock,
  GitBranch,
  FileText,
  History,
} from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';

interface Campaign {
  id: number;
  name: string;
  message: string;
  type: 'broadcast' | 'sequence' | 'reminder';
  status: 'draft' | 'scheduled' | 'sending' | 'completed' | 'failed' | 'paused';
  target_audience: string;
  total_recipients: number;
  sent_count: number;
  failed_count: number;
  scheduled_at: string | null;
  created_at: string;
  creator: { name: string };
}

interface SmsLog {
  id: number;
  phone: string;
  message: string;
  status: 'pending' | 'sent' | 'delivered' | 'failed';
  created_at: string;
}

interface Props {
  campaigns: {
    data: Campaign[];
    current_page: number;
    last_page: number;
    total: number;
  };
  stats: {
    total_sent: number;
    total_failed: number;
    campaigns_active: number;
    sequences_active: number;
  };
  recentLogs: SmsLog[];
}

const statusConfig: Record<string, { label: string; color: string; icon: React.ComponentType<any> }> = {
  draft: { label: 'Draft', color: 'secondary', icon: FileText },
  scheduled: { label: 'Scheduled', color: 'default', icon: Clock },
  sending: { label: 'Sending', color: 'default', icon: Send },
  completed: { label: 'Completed', color: 'default', icon: CheckCircle },
  failed: { label: 'Failed', color: 'destructive', icon: XCircle },
  paused: { label: 'Paused', color: 'secondary', icon: Clock },
};

const typeLabels: Record<string, string> = {
  broadcast: 'Broadcast',
  sequence: 'Sequence',
  reminder: 'Reminder',
};

export default function SmsIndex({ campaigns, stats, recentLogs }: Props) {
  return (
    <AppLayout>
      <Head title="SMS Messaging" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">SMS Messaging</h1>
            <p className="text-muted-foreground">
              Send bulk SMS, manage sequences, and track delivery
            </p>
          </div>
          <div className="flex gap-2">
            <Link href="/sms/sequences">
              <Button variant="outline">
                <GitBranch className="mr-2 h-4 w-4" />
                Sequences
              </Button>
            </Link>
            <Link href="/sms/templates">
              <Button variant="outline">
                <FileText className="mr-2 h-4 w-4" />
                Templates
              </Button>
            </Link>
            <Link href="/sms/create">
              <Button>
                <Plus className="mr-2 h-4 w-4" />
                New Campaign
              </Button>
            </Link>
          </div>
        </div>

        {/* Stats */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Total Sent</CardTitle>
              <Send className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats.total_sent.toLocaleString()}</div>
              <p className="text-xs text-muted-foreground">All time messages</p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Failed</CardTitle>
              <XCircle className="h-4 w-4 text-destructive" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats.total_failed.toLocaleString()}</div>
              <p className="text-xs text-muted-foreground">
                {stats.total_sent > 0
                  ? `${((stats.total_failed / (stats.total_sent + stats.total_failed)) * 100).toFixed(1)}% failure rate`
                  : 'No failures'}
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Active Campaigns</CardTitle>
              <MessageSquare className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats.campaigns_active}</div>
              <p className="text-xs text-muted-foreground">Sending or scheduled</p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Active Sequences</CardTitle>
              <GitBranch className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats.sequences_active}</div>
              <p className="text-xs text-muted-foreground">Automated follow-ups</p>
            </CardContent>
          </Card>
        </div>

        {/* Campaigns Table */}
        <Card>
          <CardHeader>
            <CardTitle>Campaigns</CardTitle>
            <CardDescription>Recent and active SMS campaigns</CardDescription>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Recipients</TableHead>
                  <TableHead className="text-right">Sent</TableHead>
                  <TableHead className="text-right">Failed</TableHead>
                  <TableHead>Created</TableHead>
                  <TableHead></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {campaigns.data.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={8} className="text-center py-8 text-muted-foreground">
                      No campaigns yet. Create your first campaign to get started.
                    </TableCell>
                  </TableRow>
                ) : (
                  campaigns.data.map((campaign) => {
                    const status = statusConfig[campaign.status] || statusConfig.draft;
                    const StatusIcon = status.icon;
                    return (
                      <TableRow key={campaign.id}>
                        <TableCell className="font-medium">{campaign.name}</TableCell>
                        <TableCell>
                          <Badge variant="outline">{typeLabels[campaign.type]}</Badge>
                        </TableCell>
                        <TableCell>
                          <Badge variant={status.color as any} className="gap-1">
                            <StatusIcon className="h-3 w-3" />
                            {status.label}
                          </Badge>
                        </TableCell>
                        <TableCell className="text-right">
                          {campaign.total_recipients.toLocaleString()}
                        </TableCell>
                        <TableCell className="text-right text-green-600">
                          {campaign.sent_count.toLocaleString()}
                        </TableCell>
                        <TableCell className="text-right text-red-600">
                          {campaign.failed_count.toLocaleString()}
                        </TableCell>
                        <TableCell className="text-muted-foreground">
                          {formatDistanceToNow(new Date(campaign.created_at), { addSuffix: true })}
                        </TableCell>
                        <TableCell>
                          <div className="flex gap-1">
                            <Link href={`/sms/campaigns/${campaign.id}`}>
                              <Button variant="ghost" size="sm">
                                View
                              </Button>
                            </Link>
                            {['draft', 'scheduled'].includes(campaign.status) && (
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                  if (confirm('Send this campaign now?')) {
                                    router.post(`/sms/campaigns/${campaign.id}/send`);
                                  }
                                }}
                              >
                                <Send className="h-4 w-4" />
                              </Button>
                            )}
                          </div>
                        </TableCell>
                      </TableRow>
                    );
                  })
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        {/* Recent Activity */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle>Recent Activity</CardTitle>
              <CardDescription>Latest SMS messages sent</CardDescription>
            </div>
            <Link href="/sms/logs">
              <Button variant="outline" size="sm">
                <History className="mr-2 h-4 w-4" />
                View All Logs
              </Button>
            </Link>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              {recentLogs.length === 0 ? (
                <p className="text-center text-muted-foreground py-4">
                  No messages sent yet
                </p>
              ) : (
                recentLogs.map((log) => (
                  <div
                    key={log.id}
                    className="flex items-center justify-between p-3 rounded-lg border"
                  >
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2">
                        <span className="font-mono text-sm">{log.phone}</span>
                        <Badge
                          variant={
                            log.status === 'sent' || log.status === 'delivered'
                              ? 'default'
                              : log.status === 'failed'
                              ? 'destructive'
                              : 'secondary'
                          }
                          className="text-xs"
                        >
                          {log.status}
                        </Badge>
                      </div>
                      <p className="text-sm text-muted-foreground truncate mt-1">
                        {log.message}
                      </p>
                    </div>
                    <span className="text-xs text-muted-foreground whitespace-nowrap ml-4">
                      {formatDistanceToNow(new Date(log.created_at), { addSuffix: true })}
                    </span>
                  </div>
                ))
              )}
            </div>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
