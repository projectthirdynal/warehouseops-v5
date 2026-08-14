import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  Settings,
  Users,
  ArrowRightLeft,
  BarChart3,
  Play,
  Trash2,
  Plus,
  AlertCircle,
  CheckCircle2,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogTrigger,
} from '@/components/ui/dialog';
import { useToast } from '@/hooks/use-toast';
import DistributionRuleForm from './components/DistributionRuleForm';
import ManualAssignmentModal from './components/ManualAssignmentModal';
import AgentWorkloadCard from './components/AgentWorkloadCard';
import PredictiveModelPanel from './components/PredictiveModelPanel';

interface DistributionRule {
  id: number;
  name: string;
  strategy: string;
  priority: number;
  is_active: boolean;
}

interface Agent {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
}

interface AgentWorkload {
  agent_id: number;
  active_leads_count: number;
  today_assigned_count: number;
  today_converted_count: number;
  last_assigned_at: string | null;
}

interface Props {
  rules: DistributionRule[];
  queue: {
    id: number;
    lead_id: number;
    status: string;
    assigned_agent_id: number | null;
    attempt_count: number;
    created_at: string;
    lead?: { name: string; phone: string };
    assigned_agent?: { name: string };
  }[];
  agents: Agent[];
  workloads: Record<number, AgentWorkload>;
}

const strategyColors: Record<string, string> = {
  round_robin: 'bg-info/10 text-info',
  weighted: 'bg-primary/10 text-primary',
  skill_match: 'bg-success/10 text-success',
  territory: 'bg-warning/10 text-warning',
  hybrid: 'bg-indigo-100 text-indigo-800',
  predictive: 'bg-purple-100 text-purple-800',
};

const strategyLabels: Record<string, string> = {
  round_robin: 'Round Robin',
  weighted: 'Weighted',
  skill_match: 'Skill Match',
  territory: 'Territory',
  hybrid: 'Hybrid',
  predictive: 'Predictive (ML)',
};

export default function DistributionIndex({ rules, queue, agents, workloads }: Props) {
  const toast = useToast();
  const [ruleOpen, setRuleOpen] = useState(false);
  const [editingRule, setEditingRule] = useState<DistributionRule | null>(null);
  const [assignOpen, setAssignOpen] = useState(false);

  const handleAutoDistribute = () => {
    router.post(
      '/distribution/auto-distribute',
      { limit: 10 },
      {
        onSuccess: () => toast.success('Auto-distribution triggered'),
        onError: () => toast.error('Auto-distribution failed'),
      }
    );
  };

  const handleDeleteRule = (rule: DistributionRule) => {
    if (confirm(`Delete rule "${rule.name}"?`)) {
      router.delete(`/distribution/rules/${rule.id}`, {
        onSuccess: () => toast.success('Rule deleted'),
        onError: () => toast.error('Failed to delete rule'),
      });
    }
  };

  const pendingCount = queue.filter((q) => q.status === 'pending').length;
  const assignedCount = queue.filter((q) => q.status === 'assigned').length;

  return (
    <AppLayout>
      <Head title="Lead Distribution" />
      <div className="space-y-4">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold font-display tracking-tight">Lead Distribution</h1>
            <p className="text-sm text-muted-foreground">
              Configure rules, monitor queue, and manage agent workloads.
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Button variant="outline" onClick={() => setAssignOpen(true)}>
              <ArrowRightLeft className="mr-1.5 h-4 w-4" />
              Manual Assign
            </Button>
            <Button onClick={handleAutoDistribute}>
              <Play className="mr-1.5 h-4 w-4" />
              Auto Distribute
            </Button>
          </div>
        </div>

        {/* Stats */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground">
                Active Rules
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">{rules.length}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground">
                Queue Pending
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">{pendingCount}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground">
                Queue Assigned
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">{assignedCount}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground">
                Active Agents
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">
                {agents.filter((a) => a.is_active).length}
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Rules */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium flex items-center gap-2">
              <Settings className="h-4 w-4" />
              Distribution Rules
            </CardTitle>
            <Dialog open={ruleOpen} onOpenChange={setRuleOpen}>
              <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                  <Plus className="mr-1 h-3.5 w-3.5" />
                  Add Rule
                </Button>
              </DialogTrigger>
              <DialogContent className="max-w-lg">
                <DialogHeader>
                  <DialogTitle>{editingRule ? 'Edit' : 'New'} Distribution Rule</DialogTitle>
                  <DialogDescription>
                    Define conditions and priority for how leads are automatically distributed.
                  </DialogDescription>
                </DialogHeader>
                <DistributionRuleForm
                  rule={editingRule}
                  onSuccess={() => {
                    setRuleOpen(false);
                    setEditingRule(null);
                  }}
                />
              </DialogContent>
            </Dialog>
          </CardHeader>
          <CardContent>
            {rules.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-muted-foreground">
                <AlertCircle className="h-8 w-8 mb-2 opacity-40" />
                <p className="text-sm">No distribution rules configured.</p>
                <p className="text-xs mt-1">Add a rule to enable intelligent lead routing.</p>
              </div>
            ) : (
              <div className="space-y-2">
                {rules.map((rule) => (
                  <div
                    key={rule.id}
                    className="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50 transition-colors"
                  >
                    <div className="flex items-center gap-3">
                      <Badge className={strategyColors[rule.strategy] || 'bg-muted'}>
                        {strategyLabels[rule.strategy] || rule.strategy}
                      </Badge>
                      <div>
                        <p className="text-sm font-medium">{rule.name}</p>
                        <p className="text-xs text-muted-foreground">
                          Priority {rule.priority} · {rule.is_active ? 'Active' : 'Inactive'}
                        </p>
                      </div>
                    </div>
                    <div className="flex items-center gap-1">
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                          setEditingRule(rule);
                          setRuleOpen(true);
                        }}
                      >
                        Edit
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        className="text-destructive"
                        onClick={() => handleDeleteRule(rule)}
                      >
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Agent Workloads */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium flex items-center gap-2">
              <Users className="h-4 w-4" />
              Agent Workloads
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              {agents.map((agent) => (
                <AgentWorkloadCard key={agent.id} agent={agent} workload={workloads[agent.id]} />
              ))}
            </div>
          </CardContent>
        </Card>

        {/* Predictive Model */}
        <PredictiveModelPanel />

        {/* Recent Queue */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium flex items-center gap-2">
              <BarChart3 className="h-4 w-4" />
              Recent Queue Activity
            </CardTitle>
          </CardHeader>
          <CardContent>
            {queue.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-muted-foreground">
                <CheckCircle2 className="h-8 w-8 mb-2 opacity-40" />
                <p className="text-sm">Queue is empty.</p>
              </div>
            ) : (
              <div className="space-y-2">
                {queue.slice(0, 10).map((item) => (
                  <div
                    key={item.id}
                    className="flex items-center justify-between rounded-lg border p-3"
                  >
                    <div className="flex items-center gap-3">
                      <Badge
                        variant={
                          item.status === 'pending'
                            ? 'outline'
                            : item.status === 'assigned'
                              ? 'default'
                              : 'destructive'
                        }
                      >
                        {item.status}
                      </Badge>
                      <div>
                        <p className="text-sm font-medium">Lead #{item.lead_id}</p>
                        <p className="text-xs text-muted-foreground">
                          {item.lead?.name} · {item.attempt_count} attempts
                        </p>
                      </div>
                    </div>
                    <div className="text-xs text-muted-foreground">
                      {item.assigned_agent ? `→ ${item.assigned_agent.name}` : 'Unassigned'}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Manual Assignment Modal */}
      <Dialog open={assignOpen} onOpenChange={setAssignOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Manual Lead Assignment</DialogTitle>
            <DialogDescription>
              Manually assign selected leads to a specific agent.
            </DialogDescription>
          </DialogHeader>
          <ManualAssignmentModal agents={agents} onClose={() => setAssignOpen(false)} />
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
