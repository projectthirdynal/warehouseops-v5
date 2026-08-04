import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  Activity,
  AlertTriangle,
  Brain,
  Calendar,
  Filter,
  Flame,
  Moon,
  Phone,
  RefreshCw,
  Search,
  TrendingDown,
  User,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

interface Agent {
  agent_id: number;
  name: string;
  risk_score: number;
  risk_level: string;
  features: Record<string, number | boolean | string | null>;
  recommendation: string;
  calculated_at: string;
}

interface Summary {
  total_agents: number;
  critical: number;
  high: number;
  medium: number;
  low: number;
  avg_risk_score: number;
  at_risk_count: number;
  last_calculated_at: string | null;
}

interface Props {
  agents: Agent[];
  summary: Summary;
  filters: {
    risk_level?: string;
    search?: string;
  };
}

const riskStyles: Record<string, { badge: string; bar: string; icon: typeof AlertTriangle }> = {
  critical: { badge: 'bg-destructive text-white', bar: 'bg-destructive', icon: Flame },
  high: { badge: 'bg-warning text-white', bar: 'bg-warning', icon: AlertTriangle },
  medium: { badge: 'bg-info text-white', bar: 'bg-info', icon: Activity },
  low: { badge: 'bg-success text-white', bar: 'bg-success', icon: Activity },
};

export default function BurnoutDashboard({ agents, summary, filters }: Props) {
  const [search, setSearch] = useState(filters?.search || '');
  const [riskFilter, setRiskFilter] = useState(filters?.risk_level || 'all');
  const [recalculating, setRecalculating] = useState(false);
  const [selected, setSelected] = useState<Agent | null>(null);

  const handleSearch = () => {
    router.get(
      '/agents/burnout',
      {
        search,
        risk_level: riskFilter !== 'all' ? riskFilter : undefined,
      },
      { preserveState: true }
    );
  };

  const handleRecalculate = async () => {
    setRecalculating(true);
    try {
      await fetch('/agents/burnout/recalculate', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
        },
      });
      router.reload();
    } catch {
      // ignore
    } finally {
      setRecalculating(false);
    }
  };

  const summaryCards = [
    {
      title: 'Average Risk Score',
      value: summary.avg_risk_score.toFixed(1),
      icon: Brain,
      color: 'text-primary',
    },
    {
      title: 'At Risk',
      value: summary.at_risk_count,
      icon: AlertTriangle,
      color: 'text-warning',
    },
    {
      title: 'Critical',
      value: summary.critical,
      icon: Flame,
      color: 'text-destructive',
    },
    {
      title: 'Total Monitored',
      value: summary.total_agents,
      icon: User,
      color: 'text-muted-foreground',
    },
  ];

  return (
    <AppLayout>
      <Head title="Burnout Prediction" />
      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-xl font-bold font-display tracking-tight flex items-center gap-2">
              <Brain className="h-5 w-5 text-primary" />
              Burnout Prediction
            </h1>
            <p className="text-muted-foreground">
              ML-style risk scoring from agent activity patterns
            </p>
          </div>
          <Button variant="outline" size="sm" onClick={handleRecalculate} disabled={recalculating}>
            <RefreshCw className={`mr-1.5 h-4 w-4 ${recalculating ? 'animate-spin' : ''}`} />
            Recalculate
          </Button>
        </div>

        {/* Summary Cards */}
        <div className="grid gap-4 md:grid-cols-4">
          {summaryCards.map((card) => (
            <Card key={card.title}>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  {card.title}
                </CardTitle>
                <card.icon className={`h-4 w-4 ${card.color}`} />
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold font-display">{card.value}</div>
              </CardContent>
            </Card>
          ))}
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="pt-6">
            <div className="flex flex-col gap-4 md:flex-row">
              <div className="relative flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  placeholder="Search by agent name..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                  className="pl-10"
                />
              </div>
              <Select value={riskFilter} onValueChange={setRiskFilter}>
                <SelectTrigger className="w-full md:w-[180px]">
                  <Filter className="mr-2 h-4 w-4" />
                  <SelectValue placeholder="Risk Level" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Levels</SelectItem>
                  <SelectItem value="critical">Critical</SelectItem>
                  <SelectItem value="high">High</SelectItem>
                  <SelectItem value="medium">Medium</SelectItem>
                  <SelectItem value="low">Low</SelectItem>
                </SelectContent>
              </Select>
              <Button onClick={handleSearch}>Filter</Button>
            </div>
          </CardContent>
        </Card>

        {/* Risk Distribution */}
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">Risk Distribution</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex items-center gap-4">
              <div className="h-4 flex-1 rounded-full bg-muted overflow-hidden flex">
                {(['critical', 'high', 'medium', 'low'] as const).map((level) => {
                  const count = summary[level as keyof Summary] as number;
                  const pct = summary.total_agents > 0 ? (count / summary.total_agents) * 100 : 0;
                  return (
                    <div
                      key={level}
                      className={`${riskStyles[level].bar} transition-all`}
                      style={{ width: `${pct}%` }}
                    />
                  );
                })}
              </div>
            </div>
            <div className="mt-3 flex flex-wrap gap-3 text-sm">
              {(['critical', 'high', 'medium', 'low'] as const).map((level) => (
                <div key={level} className="flex items-center gap-1.5">
                  <div className={`h-2.5 w-2.5 rounded-full ${riskStyles[level].bar}`} />
                  <span className="capitalize text-muted-foreground">{level}:</span>
                  <span className="font-medium">{summary[level as keyof Summary] as number}</span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>

        {/* Agents Table */}
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">Agent Risk Scores</CardTitle>
            <CardDescription>
              Last calculated:{' '}
              {summary.last_calculated_at
                ? new Date(summary.last_calculated_at).toLocaleString('en-PH')
                : 'Never'}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-muted-foreground">
                    <th className="py-2 text-left font-medium">Agent</th>
                    <th className="py-2 text-left font-medium">Risk</th>
                    <th className="py-2 text-left font-medium">Drivers</th>
                    <th className="py-2 text-right font-medium">Score</th>
                  </tr>
                </thead>
                <tbody>
                  {agents.map((agent) => {
                    const style = riskStyles[agent.risk_level] ?? riskStyles.low;
                    const Icon = style.icon;
                    return (
                      <tr
                        key={agent.agent_id}
                        className="border-b last:border-b-0 hover:bg-muted/50 cursor-pointer"
                        onClick={() => setSelected(agent)}
                      >
                        <td className="py-3">
                          <div className="flex items-center gap-2">
                            <div className="h-8 w-8 rounded-full bg-primary/10 flex items-center justify-center text-xs font-bold">
                              {agent.name
                                .split(' ')
                                .map((n) => n[0])
                                .join('')
                                .slice(0, 2)
                                .toUpperCase()}
                            </div>
                            <span className="font-medium">{agent.name}</span>
                          </div>
                        </td>
                        <td className="py-3">
                          <Badge className={style.badge}>
                            <Icon className="mr-1 h-3 w-3" />
                            <span className="capitalize">{agent.risk_level}</span>
                          </Badge>
                        </td>
                        <td className="py-3">
                          <div className="flex flex-wrap gap-1">
                            {typeof agent.features.consecutive_work_days === 'number' &&
                              agent.features.consecutive_work_days >= 5 && (
                                <Badge variant="outline" className="text-xs">
                                  <Calendar className="mr-1 h-3 w-3" />
                                  {agent.features.consecutive_work_days}d streak
                                </Badge>
                              )}
                            {typeof agent.features.rest_days_14d === 'number' &&
                              agent.features.rest_days_14d <= 2 && (
                                <Badge variant="outline" className="text-xs">
                                  <Moon className="mr-1 h-3 w-3" />
                                  low rest
                                </Badge>
                              )}
                            {typeof agent.features.avg_daily_calls_7d === 'number' &&
                              agent.features.avg_daily_calls_7d >= 30 && (
                                <Badge variant="outline" className="text-xs">
                                  <Phone className="mr-1 h-3 w-3" />
                                  {agent.features.avg_daily_calls_7d} calls/day
                                </Badge>
                              )}
                            {Number(agent.features.conversion_trend) < -0.15 && (
                              <Badge variant="outline" className="text-xs">
                                <TrendingDown className="mr-1 h-3 w-3" />
                                declining conversion
                              </Badge>
                            )}
                          </div>
                        </td>
                        <td className="py-3 text-right">
                          <div className="flex items-center justify-end gap-2">
                            <span className="font-bold">{agent.risk_score}</span>
                            <div className="h-2 w-16 rounded-full bg-muted overflow-hidden">
                              <div
                                className={`h-full ${style.bar}`}
                                style={{ width: `${agent.risk_score}%` }}
                              />
                            </div>
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
              {agents.length === 0 && (
                <div className="py-8 text-center text-muted-foreground">
                  No agents match the current filters.
                </div>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Agent Detail Modal */}
        {selected && (
          <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            onClick={() => setSelected(null)}
          >
            <div
              className="w-full max-w-2xl max-h-[80vh] overflow-y-auto rounded-lg border bg-background p-6 shadow-lg"
              onClick={(e) => e.stopPropagation()}
            >
              <div className="flex items-center justify-between">
                <h2 className="text-lg font-bold font-display">{selected.name}</h2>
                <Button variant="ghost" size="sm" onClick={() => setSelected(null)}>
                  Close
                </Button>
              </div>
              <p className="mt-2 text-sm text-muted-foreground">{selected.recommendation}</p>

              <div className="mt-6 grid gap-4 sm:grid-cols-2">
                {Object.entries(selected.features).map(([key, value]) => (
                  <div key={key} className="rounded-lg border p-3">
                    <div className="text-xs text-muted-foreground capitalize">
                      {key.replace(/_/g, ' ')}
                    </div>
                    <div className="mt-1 font-medium">
                      {typeof value === 'boolean' ? (value ? 'Yes' : 'No') : value}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
