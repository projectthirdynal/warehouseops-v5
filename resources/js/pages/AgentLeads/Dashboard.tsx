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
} from 'lucide-react';
import AgentLayout from '@/layouts/AgentLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
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

interface Props {
  earnings: Earnings;
  recent_commissions: Commission[];
  lead_history: LeadHistoryItem[];
  leaderboard: Leaderboard;
  workload: Workload;
  agent: AgentInfo;
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

        {/* Workload Row */}
        <div className="grid gap-4 md:grid-cols-3">
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
