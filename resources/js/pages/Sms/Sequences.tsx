import AppLayout from '@/layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { ArrowLeft, Plus, GitBranch, Clock, Users, Zap } from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';

interface SequenceStep {
  id: number;
  step_order: number;
  message: string;
  delay_minutes: number;
  delay_type: 'minutes' | 'hours' | 'days';
  is_active: boolean;
}

interface Sequence {
  id: number;
  name: string;
  description: string | null;
  trigger_event: string;
  is_active: boolean;
  created_at: string;
  creator: { name: string };
  steps: SequenceStep[];
  enrollments_count: number;
}

interface Props {
  sequences: {
    data: Sequence[];
    current_page: number;
    last_page: number;
    total: number;
  };
}

const triggerLabels: Record<string, string> = {
  waybill_created: 'Waybill Created',
  waybill_dispatched: 'Waybill Dispatched',
  waybill_out_for_delivery: 'Out for Delivery',
  waybill_delivered: 'Delivered',
  waybill_returned: 'Returned',
  lead_created: 'Lead Created',
  lead_sale: 'Lead Sale',
};

const formatDelay = (minutes: number, type: string) => {
  switch (type) {
    case 'hours':
      return `${minutes} hour${minutes > 1 ? 's' : ''}`;
    case 'days':
      return `${minutes} day${minutes > 1 ? 's' : ''}`;
    default:
      return `${minutes} minute${minutes > 1 ? 's' : ''}`;
  }
};

export default function Sequences({ sequences }: Props) {
  const toggleSequence = (sequence: Sequence) => {
    router.post(`/sms/sequences/${sequence.id}/toggle`);
  };

  return (
    <AppLayout>
      <Head title="SMS Sequences" />

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
              <h1 className="text-2xl font-bold tracking-tight">SMS Sequences</h1>
              <p className="text-muted-foreground">
                Automated follow-up messages triggered by events
              </p>
            </div>
          </div>
          <Link href="/sms/sequences/create">
            <Button>
              <Plus className="mr-2 h-4 w-4" />
              New Sequence
            </Button>
          </Link>
        </div>

        {/* Info Card */}
        <Card className="bg-blue-50 dark:bg-blue-950 border-blue-200 dark:border-blue-800">
          <CardContent className="pt-6">
            <div className="flex items-start gap-3">
              <Zap className="h-5 w-5 text-blue-600 mt-0.5" />
              <div>
                <h3 className="font-medium text-blue-900 dark:text-blue-100">
                  How Sequences Work
                </h3>
                <p className="text-sm text-blue-700 dark:text-blue-300 mt-1">
                  Sequences automatically send SMS messages when specific events occur.
                  For example, send a delivery notification when a waybill status changes to "Out for Delivery",
                  then a follow-up thank you message 24 hours after delivery.
                </p>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Sequences List */}
        <Card>
          <CardHeader>
            <CardTitle>Active Sequences</CardTitle>
            <CardDescription>
              {sequences.total} sequence{sequences.total !== 1 ? 's' : ''} configured
            </CardDescription>
          </CardHeader>
          <CardContent>
            {sequences.data.length === 0 ? (
              <div className="text-center py-12">
                <GitBranch className="h-12 w-12 text-muted-foreground mx-auto mb-4" />
                <h3 className="text-lg font-medium">No sequences yet</h3>
                <p className="text-muted-foreground mt-1 mb-4">
                  Create your first automated sequence to get started
                </p>
                <Link href="/sms/sequences/create">
                  <Button>
                    <Plus className="mr-2 h-4 w-4" />
                    Create Sequence
                  </Button>
                </Link>
              </div>
            ) : (
              <div className="space-y-4">
                {sequences.data.map((sequence) => (
                  <div
                    key={sequence.id}
                    className="border rounded-lg p-4 space-y-4"
                  >
                    <div className="flex items-start justify-between">
                      <div className="flex-1">
                        <div className="flex items-center gap-3">
                          <h3 className="font-medium">{sequence.name}</h3>
                          <Badge variant={sequence.is_active ? 'default' : 'secondary'}>
                            {sequence.is_active ? 'Active' : 'Inactive'}
                          </Badge>
                        </div>
                        {sequence.description && (
                          <p className="text-sm text-muted-foreground mt-1">
                            {sequence.description}
                          </p>
                        )}
                        <div className="flex items-center gap-4 mt-2 text-sm text-muted-foreground">
                          <span className="flex items-center gap-1">
                            <Zap className="h-4 w-4" />
                            Trigger: {triggerLabels[sequence.trigger_event] || sequence.trigger_event}
                          </span>
                          <span className="flex items-center gap-1">
                            <Users className="h-4 w-4" />
                            {sequence.enrollments_count} enrolled
                          </span>
                        </div>
                      </div>
                      <div className="flex items-center gap-2">
                        <span className="text-sm text-muted-foreground">
                          {sequence.is_active ? 'Active' : 'Inactive'}
                        </span>
                        <Switch
                          checked={sequence.is_active}
                          onCheckedChange={() => toggleSequence(sequence)}
                        />
                      </div>
                    </div>

                    {/* Steps */}
                    <div className="pl-4 border-l-2 border-muted space-y-3">
                      {sequence.steps.map((step, index) => (
                        <div key={step.id} className="relative">
                          <div className="absolute -left-[21px] top-1 h-3 w-3 rounded-full bg-primary" />
                          <div className="flex items-start gap-3">
                            <div className="flex-1 min-w-0">
                              <div className="flex items-center gap-2">
                                <span className="text-xs font-medium text-muted-foreground">
                                  Step {step.step_order}
                                </span>
                                {index > 0 && (
                                  <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                    <Clock className="h-3 w-3" />
                                    {formatDelay(step.delay_minutes, step.delay_type)} after previous
                                  </span>
                                )}
                              </div>
                              <p className="text-sm mt-1 truncate">{step.message}</p>
                            </div>
                          </div>
                        </div>
                      ))}
                    </div>

                    <div className="text-xs text-muted-foreground">
                      Created by {sequence.creator.name} {formatDistanceToNow(new Date(sequence.created_at), { addSuffix: true })}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
