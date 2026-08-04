import { useState, useEffect, useCallback } from 'react';
import { Head } from '@inertiajs/react';
import {
  Scale,
  AlertTriangle,
  TrendingUp,
  Pause,
  Play,
  RefreshCw,
  Zap,
  Users,
  Activity,
  Gauge,
  CheckCircle,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
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
import { Progress } from '@/components/ui/progress';

interface AgentWorkloadItem {
  agent_id: number;
  agent_name: string;
  active_leads: number;
  today_assigned: number;
  today_converted: number;
  max_concurrent: number;
  max_daily: number;
  utilization_pct: number;
  is_available: boolean;
  auto_assign_enabled: boolean;
  overflow_enabled: boolean;
  is_overloaded: boolean;
  last_assigned_at: string | null;
  shift_start: string | null;
  shift_end: string | null;
}

interface Snapshot {
  agents: AgentWorkloadItem[];
  overloaded_count: number;
  total_active_leads: number;
  total_capacity: number;
  available_capacity: number;
}

interface Props {
  snapshot: Snapshot;
}

export default function WorkloadBalancing({ snapshot: initialSnapshot }: Props) {
  const [snapshot, setSnapshot] = useState<Snapshot>(initialSnapshot);
  const [loading, setLoading] = useState(false);
  const [actionLoading, setActionLoading] = useState<number | null>(null);
  const [cycleResult, setCycleResult] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch('/lead-pool/api/workload-status');
      const data = await res.json();
      setSnapshot(data);
    } catch {
      // keep stale data
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const interval = setInterval(refresh, 30000);
    return () => clearInterval(interval);
  }, [refresh]);

  const rebalanceAgent = async (agentId: number) => {
    setActionLoading(agentId);
    try {
      const res = await fetch('/lead-pool/api/rebalance-agent', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
        },
        body: JSON.stringify({ agent_id: agentId }),
      });
      const data = await res.json();
      if (data.redistributed > 0) {
        setCycleResult(
          `Redistributed ${data.redistributed} leads from agent. ${data.remaining} remaining.`
        );
      } else {
        setCycleResult(data.errors?.join('; ') ?? 'No leads were redistributed.');
      }
      refresh();
    } catch {
      setCycleResult('Failed to rebalance agent.');
    } finally {
      setActionLoading(null);
      setTimeout(() => setCycleResult(null), 5000);
    }
  };

  const pauseAgent = async (agentId: number) => {
    setActionLoading(agentId);
    try {
      await fetch('/lead-pool/api/pause-agent', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
        },
        body: JSON.stringify({ agent_id: agentId }),
      });
      refresh();
    } finally {
      setActionLoading(null);
    }
  };

  const resumeAgent = async (agentId: number) => {
    setActionLoading(agentId);
    try {
      await fetch('/lead-pool/api/resume-agent', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
        },
        body: JSON.stringify({ agent_id: agentId }),
      });
      refresh();
    } finally {
      setActionLoading(null);
    }
  };

  const runBalancingCycle = async () => {
    setLoading(true);
    try {
      const res = await fetch('/lead-pool/api/run-balancing-cycle', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
        },
      });
      const data = await res.json();
      setCycleResult(
        `Cycle complete: ${data.overloaded_detected} overloaded, ${data.agents_paused} paused, ${data.leads_redistributed} redistributed.`
      );
      refresh();
    } catch {
      setCycleResult('Failed to run balancing cycle.');
    } finally {
      setLoading(false);
      setTimeout(() => setCycleResult(null), 8000);
    }
  };

  const utilizationColor = (pct: number) => {
    if (pct >= 100) return 'text-destructive';
    if (pct >= 80) return 'text-warning';
    if (pct >= 50) return 'text-info';
    return 'text-success';
  };

  return (
    <AppLayout>
      <Head title="Workload Balancing" />
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold font-display tracking-tight">Workload Balancing</h1>
            <p className="text-muted-foreground">
              Monitor agent capacity and redistribute leads from overloaded agents
            </p>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={refresh} disabled={loading}>
              <RefreshCw className={`mr-1.5 h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
              Refresh
            </Button>
            <Button size="sm" onClick={runBalancingCycle} disabled={loading}>
              <Zap className="mr-1.5 h-4 w-4" />
              Run Balancing Cycle
            </Button>
          </div>
        </div>

        {cycleResult && (
          <div className="rounded-lg border border-info/30 bg-info/5 p-3 text-sm">
            <Activity className="mr-2 inline h-4 w-4 text-info" />
            {cycleResult}
          </div>
        )}

        {/* Summary Stats */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center gap-2">
                <Users className="h-5 w-5 text-muted-foreground" />
                <div>
                  <p className="text-2xl font-bold">{snapshot.agents.length}</p>
                  <p className="text-xs text-muted-foreground">Total Agents</p>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center gap-2">
                <AlertTriangle className="h-5 w-5 text-destructive" />
                <div>
                  <p className="text-2xl font-bold text-destructive">{snapshot.overloaded_count}</p>
                  <p className="text-xs text-muted-foreground">Overloaded</p>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center gap-2">
                <Gauge className="h-5 w-5 text-info" />
                <div>
                  <p className="text-2xl font-bold">
                    {snapshot.total_active_leads}
                    <span className="text-base text-muted-foreground">
                      {' '}
                      / {snapshot.total_capacity}
                    </span>
                  </p>
                  <p className="text-xs text-muted-foreground">Active Leads / Capacity</p>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center gap-2">
                <TrendingUp className="h-5 w-5 text-success" />
                <div>
                  <p className="text-2xl font-bold text-success">{snapshot.available_capacity}</p>
                  <p className="text-xs text-muted-foreground">Available Capacity</p>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Overloaded Agents Alert */}
        {snapshot.overloaded_count > 0 && (
          <Card className="border-destructive/30">
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-destructive">
                <AlertTriangle className="h-5 w-5" />
                {snapshot.overloaded_count} Overloaded Agent
                {snapshot.overloaded_count > 1 ? 's' : ''}
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-2">
                {snapshot.agents
                  .filter((a) => a.is_overloaded)
                  .map((agent) => (
                    <div
                      key={agent.agent_id}
                      className="flex items-center justify-between rounded-lg border border-destructive/20 bg-destructive/5 p-3"
                    >
                      <div className="flex items-center gap-3">
                        <div>
                          <span className="font-medium">{agent.agent_name}</span>
                          <span className="ml-2 text-sm text-muted-foreground">
                            {agent.active_leads}/{agent.max_concurrent} active ·{' '}
                            {agent.today_assigned}/{agent.max_daily} today
                          </span>
                        </div>
                        {agent.overflow_enabled && (
                          <Badge variant="outline" className="text-xs">
                            Overflow
                          </Badge>
                        )}
                      </div>
                      <div className="flex gap-2">
                        {!agent.overflow_enabled && (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => rebalanceAgent(agent.agent_id)}
                            disabled={actionLoading === agent.agent_id}
                          >
                            <Scale className="mr-1 h-3 w-3" />
                            Rebalance
                          </Button>
                        )}
                        {agent.auto_assign_enabled && (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => pauseAgent(agent.agent_id)}
                            disabled={actionLoading === agent.agent_id}
                          >
                            <Pause className="mr-1 h-3 w-3" />
                            Pause
                          </Button>
                        )}
                      </div>
                    </div>
                  ))}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Agent Workload Table */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Scale className="h-5 w-5" />
              Agent Workload Overview
            </CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Agent</TableHead>
                  <TableHead className="text-center">Active</TableHead>
                  <TableHead className="text-center">Today</TableHead>
                  <TableHead className="text-center">Converted</TableHead>
                  <TableHead className="w-32">Utilization</TableHead>
                  <TableHead className="text-center">Status</TableHead>
                  <TableHead className="text-center">Shift</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {snapshot.agents.map((agent) => (
                  <TableRow key={agent.agent_id}>
                    <TableCell>
                      <div className="flex items-center gap-2">
                        <span className="font-medium">{agent.agent_name}</span>
                        {agent.is_overloaded && (
                          <Badge variant="destructive" className="text-xs">
                            <AlertTriangle className="mr-1 h-3 w-3" />
                            Overloaded
                          </Badge>
                        )}
                        {agent.overflow_enabled && (
                          <Badge variant="outline" className="text-xs">
                            Overflow
                          </Badge>
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="text-center">
                      <span
                        className={
                          agent.active_leads > agent.max_concurrent
                            ? 'font-bold text-destructive'
                            : ''
                        }
                      >
                        {agent.active_leads}
                      </span>
                      <span className="text-muted-foreground"> / {agent.max_concurrent}</span>
                    </TableCell>
                    <TableCell className="text-center">
                      <span
                        className={
                          agent.today_assigned > agent.max_daily ? 'font-bold text-destructive' : ''
                        }
                      >
                        {agent.today_assigned}
                      </span>
                      <span className="text-muted-foreground"> / {agent.max_daily}</span>
                    </TableCell>
                    <TableCell className="text-center">
                      <span className="text-success">{agent.today_converted}</span>
                    </TableCell>
                    <TableCell>
                      <div className="space-y-1">
                        <div className="flex items-center gap-2">
                          <Progress
                            value={Math.min(100, agent.utilization_pct)}
                            className="h-2 w-20"
                          />
                          <span
                            className={`text-xs font-medium ${utilizationColor(agent.utilization_pct)}`}
                          >
                            {agent.utilization_pct}%
                          </span>
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="text-center">
                      {agent.auto_assign_enabled ? (
                        <Badge variant="default" className="text-xs">
                          <CheckCircle className="mr-1 h-3 w-3" />
                          Active
                        </Badge>
                      ) : (
                        <Badge variant="secondary" className="text-xs">
                          <Pause className="mr-1 h-3 w-3" />
                          Paused
                        </Badge>
                      )}
                      {!agent.is_available && (
                        <Badge variant="outline" className="ml-1 text-xs">
                          Unavailable
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-center text-xs text-muted-foreground">
                      {agent.shift_start && agent.shift_end
                        ? `${agent.shift_start}–${agent.shift_end}`
                        : '—'}
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-1">
                        {agent.is_overloaded && !agent.overflow_enabled && (
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => rebalanceAgent(agent.agent_id)}
                            disabled={actionLoading === agent.agent_id}
                          >
                            <Scale className="h-3 w-3" />
                          </Button>
                        )}
                        {agent.auto_assign_enabled ? (
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => pauseAgent(agent.agent_id)}
                            disabled={actionLoading === agent.agent_id}
                          >
                            <Pause className="h-3 w-3" />
                          </Button>
                        ) : (
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => resumeAgent(agent.agent_id)}
                            disabled={actionLoading === agent.agent_id}
                          >
                            <Play className="h-3 w-3" />
                          </Button>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
                {snapshot.agents.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={8} className="text-center text-muted-foreground py-8">
                      No active agents found.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
