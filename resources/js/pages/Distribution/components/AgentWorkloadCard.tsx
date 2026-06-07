import { User, Briefcase, TrendingUp, Clock } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';

interface Agent {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
}

interface Workload {
  agent_id: number;
  active_leads_count: number;
  today_assigned_count: number;
  today_converted_count: number;
  last_assigned_at: string | null;
}

interface Props {
  agent: Agent;
  workload?: Workload;
}

export default function AgentWorkloadCard({ agent, workload }: Props) {
  const active = workload?.active_leads_count ?? 0;
  const todayAssigned = workload?.today_assigned_count ?? 0;
  const todayConverted = workload?.today_converted_count ?? 0;
  const utilization = workload ? Math.min(100, Math.round((active / 10) * 100)) : 0;

  const barColor = utilization >= 90 ? 'bg-red-500' : utilization >= 70 ? 'bg-yellow-500' : 'bg-green-500';

  return (
    <Card className="overflow-hidden">
      <CardContent className="p-4 space-y-3">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <User className="h-4 w-4 text-muted-foreground" />
            <span className="text-sm font-medium">{agent.name}</span>
          </div>
          <span className={`text-xs px-1.5 py-0.5 rounded ${agent.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}`}>
            {agent.is_active ? 'Active' : 'Inactive'}
          </span>
        </div>

        <div className="space-y-1">
          <div className="flex justify-between text-xs text-muted-foreground">
            <span>Utilization</span>
            <span>{utilization}%</span>
          </div>
          <div className="h-1.5 w-full bg-muted rounded-full overflow-hidden">
            <div className={`h-full ${barColor} transition-all`} style={{ width: `${utilization}%` }} />
          </div>
        </div>

        <div className="grid grid-cols-3 gap-2 text-center">
          <div>
            <div className="flex items-center justify-center gap-1 text-muted-foreground">
              <Briefcase className="h-3 w-3" />
            </div>
            <p className="text-lg font-semibold">{active}</p>
            <p className="text-[10px] text-muted-foreground">Active</p>
          </div>
          <div>
            <div className="flex items-center justify-center gap-1 text-muted-foreground">
              <TrendingUp className="h-3 w-3" />
            </div>
            <p className="text-lg font-semibold">{todayAssigned}</p>
            <p className="text-[10px] text-muted-foreground">Today</p>
          </div>
          <div>
            <div className="flex items-center justify-center gap-1 text-muted-foreground">
              <Clock className="h-3 w-3" />
            </div>
            <p className="text-lg font-semibold">{todayConverted}</p>
            <p className="text-[10px] text-muted-foreground">Converted</p>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
