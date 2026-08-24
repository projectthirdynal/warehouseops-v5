import { Head } from '@inertiajs/react';
import {
  Wallet,
  TrendingUp,
  TrendingDown,
  Clock,
  CheckCircle,
  Trophy,
  Users,
  Phone,
  Target,
  Flame,
  Award,
  ClipboardList,
  CheckSquare,
} from 'lucide-react';
import AgentLayout from '@/layouts/AgentLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { WeatherWidget } from '@/components/agent/WeatherWidget';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

interface Earnings {
  total_earned: number;
  pending: number;
  approved: number;
  paid: number;
  this_month: number;
  last_month: number;
  month_change: number;
  total_orders: number;
}
interface Commission {
  id: number;
  order_number: string;
  product_name: string;
  sale_amount: number;
  commission_amount: number;
  commission_rate: number;
  status: string;
  earned_at: string | null;
  paid_at: string | null;
}
interface LeadHistoryItem {
  id: number;
  cycle_number: number;
  lead_name: string;
  product: string;
  amount: number;
  outcome: string;
  status: string;
  call_count: number;
  opened_at: string | null;
  closed_at: string | null;
  handle_time_hours: number;
}
interface LeaderboardItem {
  rank: number;
  agent_id: number;
  agent_name: string;
  sales_count: number;
  revenue: number;
  is_me: boolean;
}
interface Leaderboard {
  items: LeaderboardItem[];
  my_rank: number | null;
  period: string;
}
interface Workload {
  active_leads: number;
  today_assigned: number;
  today_converted: number;
}
interface AgentInfo {
  id: number;
  name: string;
  performance_score: number;
  is_available: boolean;
}

interface GamificationSummary {
  current_streak: number;
  longest_streak: number;
  total_badges: number;
  total_badges_available: number;
  total_milestones_completed: number;
  total_milestones: number;
}
interface CoachingNoteItem {
  id: number;
  category: string;
  priority: string;
  subject: string;
  body: string;
  action_items: string[] | null;
  resolved_at: string | null;
  created_at: string;
  author: { id: number; name: string } | null;
}
interface Props {
  earnings: Earnings;
  recent_commissions: Commission[];
  lead_history: LeadHistoryItem[];
  leaderboard: Leaderboard;
  workload: Workload;
  agent: AgentInfo;
  gamification: GamificationSummary;
  coachingNotes: CoachingNoteItem[];
}

const currency = (n: number) =>
  `₱${n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const formatDate = (d: string | null) =>
  d ? new Date(d).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) : '—';

const outcomeBadge = (outcome: string) => {
  const m: Record<
    string,
    { variant: 'default' | 'secondary' | 'destructive' | 'outline'; label: string }
  > = {
    ORDERED: { variant: 'default', label: 'Sale' },
    NOT_INTERESTED: { variant: 'destructive', label: 'Not Interested' },
    CALLBACK: { variant: 'secondary', label: 'Callback' },
    NO_ANSWER: { variant: 'outline', label: 'No Answer' },
    WRONG_NUMBER: { variant: 'destructive', label: 'Wrong Number' },
    INTERESTED: { variant: 'secondary', label: 'Interested' },
  };
  const c = m[outcome] ?? { variant: 'outline' as const, label: outcome };
  return <Badge variant={c.variant}>{c.label}</Badge>;
};

const commStatusBadge = (status: string) => {
  const m: Record<
    string,
    { variant: 'default' | 'secondary' | 'destructive' | 'outline'; label: string }
  > = {
    PENDING: { variant: 'secondary', label: 'Pending' },
    APPROVED: { variant: 'default', label: 'Approved' },
    PAID: { variant: 'default', label: 'Paid' },
    CANCELLED: { variant: 'destructive', label: 'Cancelled' },
  };
  const c = m[status] ?? { variant: 'outline' as const, label: status };
  return <Badge variant={c.variant}>{c.label}</Badge>;
};

export default function AgentDashboard({
  earnings,
  recent_commissions,
  lead_history,
  leaderboard,
  workload,
  gamification,
  coachingNotes,
}: Props) {
  const up = earnings.month_change >= 0;
  return (
    <AgentLayout>
      <Head title="My Dashboard" />
      <div className="space-y-6">
        <div>
          <h1 className="text-xl font-bold font-display tracking-tight">My Dashboard</h1>
          <p className="text-muted-foreground">Track your earnings, performance, and ranking</p>
        </div>

        {/* Earnings Cards */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Total Earned
              </CardTitle>
              <Wallet className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">
                {currency(earnings.total_earned)}
              </div>
              <p className="text-xs text-muted-foreground mt-1">{earnings.total_orders} orders</p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                This Month
              </CardTitle>
              {up ? (
                <TrendingUp className="h-4 w-4 text-success" />
              ) : (
                <TrendingDown className="h-4 w-4 text-destructive" />
              )}
            </CardHeader>
            <CardContent>
              <div
                className={`text-xl font-bold font-display ${up ? 'text-success' : 'text-destructive'}`}
              >
                {currency(earnings.this_month)}
              </div>
              <p className="text-xs text-muted-foreground mt-1">
                {up ? '+' : ''}
                {currency(earnings.month_change)} vs last month
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Pending</CardTitle>
              <Clock className="h-4 w-4 text-warning" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display text-warning">
                {currency(earnings.pending)}
              </div>
              <p className="text-xs text-muted-foreground mt-1">Awaiting approval</p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Paid</CardTitle>
              <CheckCircle className="h-4 w-4 text-success" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display text-success">
                {currency(earnings.paid)}
              </div>
              <p className="text-xs text-muted-foreground mt-1">Total paid out</p>
            </CardContent>
          </Card>
        </div>

        {/* Weather Widget */}
        <div className="grid gap-4 md:grid-cols-3">
          <div className="md:col-span-1">
            <WeatherWidget />
          </div>

          {/* Workload Row */}
          <div className="md:col-span-2 grid gap-4 md:grid-cols-3">
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Active Leads
                </CardTitle>
                <Users className="h-4 w-4 text-info" />
              </CardHeader>
              <CardContent>
                <div className="text-xl font-bold font-display text-info">
                  {workload.active_leads}
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Assigned Today
                </CardTitle>
                <Phone className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent>
                <div className="text-xl font-bold font-display">{workload.today_assigned}</div>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Converted Today
                </CardTitle>
                <Target className="h-4 w-4 text-success" />
              </CardHeader>
              <CardContent>
                <div className="text-xl font-bold font-display text-success">
                  {workload.today_converted}
                </div>
              </CardContent>
            </Card>
          </div>
        </div>

        {/* Leaderboard + Recent Commissions */}
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-sm font-medium">
                <Trophy className="h-4 w-4 text-warning" /> Monthly Leaderboard
              </CardTitle>
            </CardHeader>
            <CardContent>
              {leaderboard.my_rank && (
                <div className="mb-3 rounded-lg bg-warning/10 px-3 py-2 text-sm">
                  Your rank: <span className="font-bold">#{leaderboard.my_rank}</span>
                </div>
              )}
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-12">#</TableHead>
                    <TableHead>Agent</TableHead>
                    <TableHead className="text-right">Sales</TableHead>
                    <TableHead className="text-right">Revenue</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {leaderboard.items.length > 0 ? (
                    leaderboard.items.map((item) => (
                      <TableRow key={item.agent_id} className={item.is_me ? 'bg-primary/5' : ''}>
                        <TableCell className="font-medium">
                          {item.rank <= 3 ? (
                            <span
                              className={
                                item.rank === 1
                                  ? 'text-warning'
                                  : item.rank === 2
                                    ? 'text-muted-foreground'
                                    : 'text-orange-600'
                              }
                            >
                              {item.rank}
                            </span>
                          ) : (
                            item.rank
                          )}
                        </TableCell>
                        <TableCell className="font-medium">
                          {item.agent_name}
                          {item.is_me && (
                            <Badge variant="outline" className="ml-2 text-xs">
                              You
                            </Badge>
                          )}
                        </TableCell>
                        <TableCell className="text-right">{item.sales_count}</TableCell>
                        <TableCell className="text-right">{currency(item.revenue)}</TableCell>
                      </TableRow>
                    ))
                  ) : (
                    <TableRow>
                      <TableCell colSpan={4} className="text-center text-muted-foreground">
                        No sales this month yet.
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-sm font-medium">
                <Wallet className="h-4 w-4" /> Recent Commissions
              </CardTitle>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Order</TableHead>
                    <TableHead>Product</TableHead>
                    <TableHead className="text-right">Amount</TableHead>
                    <TableHead>Status</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {recent_commissions.length > 0 ? (
                    recent_commissions.map((c) => (
                      <TableRow key={c.id}>
                        <TableCell className="font-medium text-xs">{c.order_number}</TableCell>
                        <TableCell className="text-xs">{c.product_name}</TableCell>
                        <TableCell className="text-right font-medium">
                          {currency(c.commission_amount)}
                        </TableCell>
                        <TableCell>{commStatusBadge(c.status)}</TableCell>
                      </TableRow>
                    ))
                  ) : (
                    <TableRow>
                      <TableCell colSpan={4} className="text-center text-muted-foreground">
                        No commissions yet.
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </div>

        {/* Gamification Summary */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-sm font-medium">
              <Trophy className="h-4 w-4 text-warning" /> My Achievements
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid gap-4 sm:grid-cols-3">
              <div className="flex items-center gap-3 rounded-lg border p-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-destructive/10">
                  <Flame className="h-5 w-5 text-destructive" />
                </div>
                <div>
                  <p className="text-2xl font-bold font-display">{gamification.current_streak}</p>
                  <p className="text-xs text-muted-foreground">
                    Day streak (best: {gamification.longest_streak})
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-3 rounded-lg border p-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-warning/10">
                  <Trophy className="h-5 w-5 text-warning" />
                </div>
                <div>
                  <p className="text-2xl font-bold font-display">
                    {gamification.total_badges}
                    <span className="text-sm font-normal text-muted-foreground">
                      /{gamification.total_badges_available}
                    </span>
                  </p>
                  <p className="text-xs text-muted-foreground">Badges earned</p>
                </div>
              </div>
              <div className="flex items-center gap-3 rounded-lg border p-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-success/10">
                  <Award className="h-5 w-5 text-success" />
                </div>
                <div>
                  <p className="text-2xl font-bold font-display">
                    {gamification.total_milestones_completed}
                    <span className="text-sm font-normal text-muted-foreground">
                      /{gamification.total_milestones}
                    </span>
                  </p>
                  <p className="text-xs text-muted-foreground">Milestones completed</p>
                </div>
              </div>
            </div>
            <a
              href="/agent/gamification"
              className="text-xs text-primary hover:underline mt-3 inline-block"
            >
              View all achievements →
            </a>
          </CardContent>
        </Card>

        {/* Coaching Notes (read-only) */}
        {coachingNotes.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-sm font-medium">
                <ClipboardList className="h-4 w-4" /> Coaching Notes
                <Badge variant="outline" className="ml-1 text-xs">
                  {coachingNotes.length} open
                </Badge>
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              {coachingNotes.map((note) => (
                <div key={note.id} className="rounded-lg border p-3">
                  <div className="flex items-center gap-2">
                    <p className="text-sm font-medium">{note.subject}</p>
                    <Badge variant="outline" className="text-xs">
                      {note.category}
                    </Badge>
                    <span
                      className={`text-xs font-medium ${
                        note.priority === 'urgent'
                          ? 'text-destructive'
                          : note.priority === 'high'
                            ? 'text-warning'
                            : note.priority === 'medium'
                              ? 'text-info'
                              : 'text-muted-foreground'
                      }`}
                    >
                      {note.priority}
                    </span>
                  </div>
                  <p className="mt-1 text-sm text-muted-foreground whitespace-pre-wrap">
                    {note.body}
                  </p>
                  {note.action_items && note.action_items.length > 0 && (
                    <ul className="mt-2 space-y-1">
                      {note.action_items.map((item, i) => (
                        <li key={i} className="flex items-center gap-2 text-xs">
                          <CheckSquare className="h-3 w-3 text-muted-foreground" />
                          {item}
                        </li>
                      ))}
                    </ul>
                  )}
                  <div className="mt-2 flex items-center gap-3 text-xs text-muted-foreground">
                    <span>From {note.author?.name ?? 'Supervisor'}</span>
                    <span>{formatDate(note.created_at)}</span>
                  </div>
                </div>
              ))}
            </CardContent>
          </Card>
        )}

        {/* Lead Cycle History */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-sm font-medium">
              <Target className="h-4 w-4" /> Recent Lead History
            </CardTitle>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Lead</TableHead>
                  <TableHead>Product</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                  <TableHead>Outcome</TableHead>
                  <TableHead className="text-right">Calls</TableHead>
                  <TableHead className="text-right">Handle (h)</TableHead>
                  <TableHead>Closed</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {lead_history.length > 0 ? (
                  lead_history.map((h) => (
                    <TableRow key={h.id}>
                      <TableCell className="font-medium text-xs">{h.lead_name}</TableCell>
                      <TableCell className="text-xs">{h.product}</TableCell>
                      <TableCell className="text-right">{currency(h.amount)}</TableCell>
                      <TableCell>{outcomeBadge(h.outcome)}</TableCell>
                      <TableCell className="text-right">{h.call_count}</TableCell>
                      <TableCell className="text-right">{h.handle_time_hours}</TableCell>
                      <TableCell className="text-xs">{formatDate(h.closed_at)}</TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={7} className="text-center text-muted-foreground">
                      No closed lead cycles yet.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      </div>
    </AgentLayout>
  );
}
