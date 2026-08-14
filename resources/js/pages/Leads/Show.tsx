import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  ArrowLeft,
  Phone,
  MapPin,
  Package,
  User,
  Clock,
  Calendar,
  Activity,
  TrendingUp,
  PhoneCall,
  CalendarClock,
  Repeat,
  ShoppingCart,
  Truck,
  FileText,
  Shield,
  ChevronDown,
  ChevronUp,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import type { Lead, LeadCycle, User as UserType, Customer, Waybill } from '@/types';

interface LifecycleEvent {
  type: string;
  action: string;
  label: string;
  description: string;
  timestamp: string;
  user?: string | null;
  cycle_id?: number | null;
  metadata?: Record<string, unknown> | null;
  old_value?: string | null;
  new_value?: string | null;
}

interface LifecycleSummary {
  total_cycles: number;
  completed_cycles: number;
  active_cycles: number;
  total_calls: number;
  total_callbacks: number;
  distinct_agents: number;
  outcomes: Record<string, number>;
  first_event_at: string | null;
  last_event_at: string | null;
  current_status: string | null;
  current_pool_status: string | null;
  current_sales_status: string | null;
  is_exhausted: boolean;
  quality_score: number | null;
}

interface Props {
  lead: Lead & {
    barangay?: string;
    street?: string;
    postal_code?: string;
    notes?: string;
    call_attempts?: number;
    last_called_at?: string;
    assigned_at?: string;
    max_cycles?: number;
    is_exhausted?: boolean;
    uploaded_by?: number;
    uploader?: UserType;
    original_agent_id?: number;
    original_agent?: UserType;
    customer?: Customer;
    cycles?: LeadCycle[];
    waybills?: Waybill[];
  };
  lifecycle: {
    events: LifecycleEvent[];
    summary: LifecycleSummary;
  };
}

const eventTypeConfig: Record<string, { icon: typeof Activity; color: string; bg: string }> = {
  import: { icon: FileText, color: 'text-info', bg: 'bg-info/10' },
  audit: { icon: Activity, color: 'text-primary', bg: 'bg-primary/10' },
  cycle: { icon: Repeat, color: 'text-warning', bg: 'bg-warning/10' },
  call: { icon: PhoneCall, color: 'text-info', bg: 'bg-info/10' },
  callback: { icon: CalendarClock, color: 'text-primary', bg: 'bg-primary/10' },
  log: { icon: FileText, color: 'text-muted-foreground', bg: 'bg-muted/50' },
  order: { icon: ShoppingCart, color: 'text-success', bg: 'bg-success/10' },
  waybill: { icon: Truck, color: 'text-warning', bg: 'bg-warning/10' },
  qa: { icon: Shield, color: 'text-primary', bg: 'bg-primary/10' },
};

const statusColors: Record<string, string> = {
  NEW: 'bg-info/10 text-info',
  CALLING: 'bg-warning/10 text-warning',
  NO_ANSWER: 'bg-muted text-foreground',
  REJECT: 'bg-destructive/10 text-destructive',
  CALLBACK: 'bg-primary/10 text-primary',
  SALE: 'bg-success/10 text-success',
  REORDER: 'bg-success/10 text-success',
  DELIVERED: 'bg-success/10 text-success',
  RETURNED: 'bg-destructive/10 text-destructive',
  CANCELLED: 'bg-muted text-foreground',
  ARCHIVED: 'bg-muted text-foreground',
};

const poolStatusColors: Record<string, string> = {
  AVAILABLE: 'bg-info/10 text-info',
  ASSIGNED: 'bg-warning/10 text-warning',
  COOLDOWN: 'bg-primary/10 text-primary',
  EXHAUSTED: 'bg-destructive/10 text-destructive',
};

function formatDate(date: string | null): string {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function timeAgo(date: string | null): string {
  if (!date) return '-';
  const diff = Date.now() - new Date(date).getTime();
  const days = Math.floor(diff / 86400000);
  const hours = Math.floor(diff / 3600000);
  const mins = Math.floor(diff / 60000);
  if (days > 0) return `${days}d ago`;
  if (hours > 0) return `${hours}h ago`;
  if (mins > 0) return `${mins}m ago`;
  return 'just now';
}

export default function LeadsShow({ lead, lifecycle }: Props) {
  const [expandedEvents, setExpandedEvents] = useState<Set<number>>(new Set());

  const toggleEvent = (idx: number) => {
    setExpandedEvents((prev) => {
      const next = new Set(prev);
      if (next.has(idx)) {
        next.delete(idx);
      } else {
        next.add(idx);
      }
      return next;
    });
  };

  const summary = lifecycle?.summary;
  const events = lifecycle?.events ?? [];

  return (
    <AppLayout>
      <Head title={`Lead #${lead.id} — ${lead.name}`} />

      <div className="space-y-4">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div className="flex items-center gap-4">
            <Button variant="ghost" size="sm" onClick={() => router.visit('/leads')}>
              <ArrowLeft className="mr-1.5 h-4 w-4" />
              Back
            </Button>
            <div>
              <h1 className="text-xl font-bold font-display tracking-tight">{lead.name}</h1>
              <p className="text-sm text-muted-foreground">
                Lead #{lead.id} · Imported {timeAgo(lead.created_at)}
              </p>
            </div>
          </div>
          <div className="flex gap-2">
            {summary && (
              <>
                <Badge
                  className={
                    statusColors[summary.current_status ?? ''] || 'bg-muted text-foreground'
                  }
                >
                  {summary.current_status || lead.status}
                </Badge>
                {summary.current_pool_status && (
                  <Badge
                    className={
                      poolStatusColors[summary.current_pool_status] || 'bg-muted text-foreground'
                    }
                  >
                    Pool: {summary.current_pool_status}
                  </Badge>
                )}
                {summary.is_exhausted && <Badge variant="destructive">Exhausted</Badge>}
              </>
            )}
          </div>
        </div>

        {/* Summary Stats */}
        {summary && (
          <div className="grid gap-4 md:grid-cols-5">
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Total Cycles
                </CardTitle>
                <Repeat className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent>
                <div className="text-xl font-bold font-display">{summary.total_cycles}</div>
                <p className="text-xs text-muted-foreground">
                  {summary.completed_cycles} completed · {summary.active_cycles} active
                </p>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Total Calls
                </CardTitle>
                <PhoneCall className="h-4 w-4 text-info" />
              </CardHeader>
              <CardContent>
                <div className="text-xl font-bold font-display text-info">
                  {summary.total_calls}
                </div>
                <p className="text-xs text-muted-foreground">{summary.total_callbacks} callbacks</p>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Distinct Agents
                </CardTitle>
                <User className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent>
                <div className="text-xl font-bold font-display">{summary.distinct_agents}</div>
                <p className="text-xs text-muted-foreground">worked on this lead</p>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Quality Score
                </CardTitle>
                <TrendingUp className="h-4 w-4 text-success" />
              </CardHeader>
              <CardContent>
                <div className="text-xl font-bold font-display text-success">
                  {summary.quality_score ?? '-'}
                </div>
                <p className="text-xs text-muted-foreground">out of 100</p>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Last Activity
                </CardTitle>
                <Clock className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent>
                <div className="text-sm font-bold font-display">
                  {timeAgo(summary.last_event_at)}
                </div>
                <p className="text-xs text-muted-foreground">{formatDate(summary.last_event_at)}</p>
              </CardContent>
            </Card>
          </div>
        )}

        <div className="grid gap-4 lg:grid-cols-3">
          {/* Left: Lead Info */}
          <div className="space-y-4">
            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Lead Information</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="flex items-start gap-2">
                  <Phone className="h-4 w-4 text-muted-foreground mt-0.5" />
                  <div>
                    <p className="text-sm font-medium">{lead.phone}</p>
                    <p className="text-xs text-muted-foreground">Phone</p>
                  </div>
                </div>
                <div className="flex items-start gap-2">
                  <MapPin className="h-4 w-4 text-muted-foreground mt-0.5" />
                  <div>
                    <p className="text-sm font-medium">{lead.address || '-'}</p>
                    <p className="text-xs text-muted-foreground">
                      {[lead.city, lead.state, lead.postal_code].filter(Boolean).join(', ') ||
                        'No address'}
                    </p>
                  </div>
                </div>
                <div className="flex items-start gap-2">
                  <Package className="h-4 w-4 text-muted-foreground mt-0.5" />
                  <div>
                    <p className="text-sm font-medium">{lead.product_name || '-'}</p>
                    <p className="text-xs text-muted-foreground">
                      {lead.product_brand ? `${lead.product_brand} · ` : ''}
                      {lead.amount ? `₱${Number(lead.amount).toLocaleString()}` : 'No amount'}
                    </p>
                  </div>
                </div>
                <div className="flex items-start gap-2">
                  <User className="h-4 w-4 text-muted-foreground mt-0.5" />
                  <div>
                    <p className="text-sm font-medium">
                      {lead.assigned_agent?.name || 'Unassigned'}
                    </p>
                    <p className="text-xs text-muted-foreground">Assigned Agent</p>
                  </div>
                </div>
                <div className="flex items-start gap-2">
                  <Calendar className="h-4 w-4 text-muted-foreground mt-0.5" />
                  <div>
                    <p className="text-sm font-medium">{formatDate(lead.assigned_at ?? null)}</p>
                    <p className="text-xs text-muted-foreground">Assigned At</p>
                  </div>
                </div>
                <div className="flex items-start gap-2">
                  <FileText className="h-4 w-4 text-muted-foreground mt-0.5" />
                  <div>
                    <p className="text-sm font-medium">{lead.source || '-'}</p>
                    <p className="text-xs text-muted-foreground">Source</p>
                  </div>
                </div>
                {lead.uploader && (
                  <div className="flex items-start gap-2">
                    <User className="h-4 w-4 text-muted-foreground mt-0.5" />
                    <div>
                      <p className="text-sm font-medium">{lead.uploader.name}</p>
                      <p className="text-xs text-muted-foreground">Imported By</p>
                    </div>
                  </div>
                )}
              </CardContent>
            </Card>

            {/* Outcome Distribution */}
            {summary && Object.keys(summary.outcomes).length > 0 && (
              <Card>
                <CardHeader>
                  <CardTitle className="text-sm">Outcome Distribution</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                  {Object.entries(summary.outcomes).map(([outcome, count]) => (
                    <div key={outcome} className="flex items-center justify-between">
                      <Badge variant="outline">{outcome}</Badge>
                      <span className="text-sm font-medium">{count}</span>
                    </div>
                  ))}
                </CardContent>
              </Card>
            )}

            {/* Cycle History */}
            {lead.cycles && lead.cycles.length > 0 && (
              <Card>
                <CardHeader>
                  <CardTitle className="text-sm">Cycle History</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                  {lead.cycles.map((cycle) => (
                    <div
                      key={cycle.id}
                      className="flex items-center justify-between border-b pb-2 last:border-0 last:pb-0"
                    >
                      <div>
                        <p className="text-sm font-medium">Cycle {cycle.cycle_number}</p>
                        <p className="text-xs text-muted-foreground">
                          {cycle.assigned_agent?.name || 'Unknown agent'}
                        </p>
                      </div>
                      <div className="text-right">
                        <Badge variant={cycle.status === 'ACTIVE' ? 'default' : 'secondary'}>
                          {cycle.status}
                        </Badge>
                        {cycle.outcome && (
                          <p className="text-xs text-muted-foreground mt-1">{cycle.outcome}</p>
                        )}
                      </div>
                    </div>
                  ))}
                </CardContent>
              </Card>
            )}
          </div>

          {/* Right: Lifecycle Timeline */}
          <div className="lg:col-span-2">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Activity className="h-4 w-4" />
                  Lifecycle Timeline
                </CardTitle>
                <CardDescription>
                  Full audit trail: import → assign → call → sale/recycle ({events.length} events)
                </CardDescription>
              </CardHeader>
              <CardContent>
                {events.length === 0 ? (
                  <div className="flex flex-col items-center justify-center py-12 text-muted-foreground">
                    <Activity className="h-8 w-8 mb-2 opacity-50" />
                    <p className="text-sm">No lifecycle events recorded</p>
                  </div>
                ) : (
                  <div className="relative space-y-1">
                    {/* Timeline line */}
                    <div className="absolute left-4 top-0 bottom-0 w-px bg-border" />

                    {events.map((event, idx) => {
                      const config = eventTypeConfig[event.type] || eventTypeConfig.audit;
                      const Icon = config.icon;
                      const isExpanded = expandedEvents.has(idx);
                      const hasMetadata = event.metadata && Object.keys(event.metadata).length > 0;
                      const hasValueChange = event.old_value || event.new_value;

                      return (
                        <div key={idx} className="relative flex gap-3 pb-4 last:pb-0">
                          {/* Icon */}
                          <div
                            className={`relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${config.bg}`}
                          >
                            <Icon className={`h-4 w-4 ${config.color}`} />
                          </div>

                          {/* Content */}
                          <div className="flex-1 min-w-0">
                            <div className="flex items-start justify-between gap-2">
                              <div className="min-w-0">
                                <p className="text-sm font-medium truncate">{event.label}</p>
                                <p className="text-xs text-muted-foreground truncate">
                                  {event.description}
                                </p>
                              </div>
                              <span className="text-xs text-muted-foreground shrink-0">
                                {formatDate(event.timestamp)}
                              </span>
                            </div>

                            {event.user && (
                              <p className="text-xs text-muted-foreground mt-0.5">
                                by {event.user}
                              </p>
                            )}

                            {(hasMetadata || hasValueChange) && (
                              <button
                                onClick={() => toggleEvent(idx)}
                                className="text-xs text-primary hover:underline mt-1 flex items-center gap-0.5"
                              >
                                {isExpanded ? (
                                  <>
                                    <ChevronUp className="h-3 w-3" />
                                    Less
                                  </>
                                ) : (
                                  <>
                                    <ChevronDown className="h-3 w-3" />
                                    Details
                                  </>
                                )}
                              </button>
                            )}

                            {isExpanded && (hasMetadata || hasValueChange) && (
                              <div className="mt-2 rounded-md bg-muted/50 p-2 space-y-1">
                                {hasValueChange && (
                                  <div className="text-xs">
                                    <span className="text-muted-foreground line-through">
                                      {event.old_value}
                                    </span>
                                    <span className="mx-1 text-muted-foreground">→</span>
                                    <span className="font-medium">{event.new_value}</span>
                                  </div>
                                )}
                                {hasMetadata && event.metadata && (
                                  <pre className="text-xs text-muted-foreground overflow-x-auto">
                                    {JSON.stringify(event.metadata, null, 2)}
                                  </pre>
                                )}
                              </div>
                            )}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
