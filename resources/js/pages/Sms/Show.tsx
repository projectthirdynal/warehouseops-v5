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
import { ArrowLeft, Send, CheckCircle, XCircle, Clock, FileText, Play, Pause } from 'lucide-react';
import { formatDistanceToNow, format } from 'date-fns';

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
  delivered_count: number;
  scheduled_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  created_at: string;
  creator: { name: string };
}

interface SmsLog {
  id: number;
  phone: string;
  message: string;
  status: 'pending' | 'sent' | 'delivered' | 'failed';
  error_message: string | null;
  sent_at: string | null;
  created_at: string;
}

interface Props {
  campaign: Campaign;
  logs: {
    data: SmsLog[];
    current_page: number;
    last_page: number;
    total: number;
  };
}

const statusConfig: Record<string, { label: string; color: string; icon: React.ComponentType<any> }> = {
  draft: { label: 'Draft', color: 'secondary', icon: FileText },
  scheduled: { label: 'Scheduled', color: 'default', icon: Clock },
  sending: { label: 'Sending', color: 'default', icon: Send },
  completed: { label: 'Completed', color: 'default', icon: CheckCircle },
  failed: { label: 'Failed', color: 'destructive', icon: XCircle },
  paused: { label: 'Paused', color: 'secondary', icon: Pause },
};

const logStatusConfig: Record<string, { color: string }> = {
  pending: { color: 'secondary' },
  sent: { color: 'default' },
  delivered: { color: 'default' },
  failed: { color: 'destructive' },
};

export default function CampaignShow({ campaign, logs }: Props) {
  const status = statusConfig[campaign.status] || statusConfig.draft;
  const StatusIcon = status.icon;

  const successRate =
    campaign.sent_count > 0
      ? ((campaign.sent_count / (campaign.sent_count + campaign.failed_count)) * 100).toFixed(1)
      : 0;

  const handleSend = () => {
    if (confirm('Are you sure you want to send this campaign now? This action cannot be undone.')) {
      router.post(`/sms/campaigns/${campaign.id}/send`);
    }
  };

  return (
    <AppLayout>
      <Head title={`Campaign: ${campaign.name}`} />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Link href="/sms">
              <Button variant="ghost" size="icon">
                <ArrowLeft className="h-5 w-5" />
              </Button>
            </Link>
            <div>
              <div className="flex items-center gap-3">
                <h1 className="text-2xl font-bold tracking-tight">{campaign.name}</h1>
                <Badge variant={status.color as any} className="gap-1">
                  <StatusIcon className="h-3 w-3" />
                  {status.label}
                </Badge>
              </div>
              <p className="text-muted-foreground">
                Created by {campaign.creator.name} {formatDistanceToNow(new Date(campaign.created_at), { addSuffix: true })}
              </p>
            </div>
          </div>

          {['draft', 'scheduled'].includes(campaign.status) && (
            <Button onClick={handleSend}>
              <Play className="mr-2 h-4 w-4" />
              Send Now
            </Button>
          )}
        </div>

        {/* Stats */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Total Recipients</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{campaign.total_recipients.toLocaleString()}</div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-green-600">Sent</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">
                {campaign.sent_count.toLocaleString()}
              </div>
              <p className="text-xs text-muted-foreground">{successRate}% success rate</p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-red-600">Failed</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-red-600">
                {campaign.failed_count.toLocaleString()}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Delivered</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{campaign.delivered_count.toLocaleString()}</div>
            </CardContent>
          </Card>
        </div>

        {/* Message Preview */}
        <Card>
          <CardHeader>
            <CardTitle>Message Content</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="p-4 bg-muted rounded-lg max-w-md">
              <div className="bg-background p-4 rounded-lg shadow-sm">
                <p className="whitespace-pre-wrap">{campaign.message}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Timeline */}
        <Card>
          <CardHeader>
            <CardTitle>Timeline</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-4">
              <div className="flex items-center gap-4">
                <div className="h-2 w-2 rounded-full bg-blue-500" />
                <div>
                  <p className="font-medium">Created</p>
                  <p className="text-sm text-muted-foreground">
                    {format(new Date(campaign.created_at), 'PPpp')}
                  </p>
                </div>
              </div>

              {campaign.scheduled_at && (
                <div className="flex items-center gap-4">
                  <div className="h-2 w-2 rounded-full bg-yellow-500" />
                  <div>
                    <p className="font-medium">Scheduled</p>
                    <p className="text-sm text-muted-foreground">
                      {format(new Date(campaign.scheduled_at), 'PPpp')}
                    </p>
                  </div>
                </div>
              )}

              {campaign.started_at && (
                <div className="flex items-center gap-4">
                  <div className="h-2 w-2 rounded-full bg-green-500" />
                  <div>
                    <p className="font-medium">Started Sending</p>
                    <p className="text-sm text-muted-foreground">
                      {format(new Date(campaign.started_at), 'PPpp')}
                    </p>
                  </div>
                </div>
              )}

              {campaign.completed_at && (
                <div className="flex items-center gap-4">
                  <div className="h-2 w-2 rounded-full bg-purple-500" />
                  <div>
                    <p className="font-medium">Completed</p>
                    <p className="text-sm text-muted-foreground">
                      {format(new Date(campaign.completed_at), 'PPpp')}
                    </p>
                  </div>
                </div>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Message Logs */}
        <Card>
          <CardHeader>
            <CardTitle>Message Logs</CardTitle>
            <CardDescription>
              {logs.total} messages ({logs.data.length} shown)
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Phone</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Sent At</TableHead>
                  <TableHead>Error</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {logs.data.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={4} className="text-center py-8 text-muted-foreground">
                      No messages sent yet
                    </TableCell>
                  </TableRow>
                ) : (
                  logs.data.map((log) => (
                    <TableRow key={log.id}>
                      <TableCell className="font-mono">{log.phone}</TableCell>
                      <TableCell>
                        <Badge variant={logStatusConfig[log.status]?.color as any || 'secondary'}>
                          {log.status}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-muted-foreground">
                        {log.sent_at ? format(new Date(log.sent_at), 'PPpp') : '-'}
                      </TableCell>
                      <TableCell className="text-red-600 text-sm max-w-xs truncate">
                        {log.error_message || '-'}
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
