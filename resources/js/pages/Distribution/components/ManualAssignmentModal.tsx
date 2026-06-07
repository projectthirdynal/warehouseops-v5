import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { useToast } from '@/hooks/use-toast';

interface Agent {
  id: number;
  name: string;
  email: string;
}

interface Props {
  agents: Agent[];
  onClose: () => void;
}

export default function ManualAssignmentModal({ agents, onClose }: Props) {
  const toast = useToast();
  const [leadId, setLeadId] = useState('');
  const [agentId, setAgentId] = useState('');
  const [reason, setReason] = useState('');
  const [mode, setMode] = useState<'assign' | 'reassign'>('assign');

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    const payload = {
      lead_id: parseInt(leadId, 10),
      agent_id: parseInt(agentId, 10),
      reason,
    };

    const url = mode === 'assign' ? '/distribution/assign' : '/distribution/reassign';

    router.post(url, payload, {
      onSuccess: () => { toast.success(mode === 'assign' ? 'Lead assigned' : 'Lead reassigned'); onClose(); },
      onError: () => toast.error('Assignment failed'),
    });
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="flex gap-2">
        <Button
          type="button"
          variant={mode === 'assign' ? 'default' : 'outline'}
          size="sm"
          onClick={() => setMode('assign')}
        >
          Assign
        </Button>
        <Button
          type="button"
          variant={mode === 'reassign' ? 'default' : 'outline'}
          size="sm"
          onClick={() => setMode('reassign')}
        >
          Reassign
        </Button>
      </div>

      <div>
        <Label htmlFor="lead-id">Lead ID</Label>
        <Input
          id="lead-id"
          type="number"
          value={leadId}
          onChange={(e) => setLeadId(e.target.value)}
          placeholder="Enter lead ID"
          required
        />
      </div>

      <div>
        <Label htmlFor="agent-id">Agent</Label>
        <Select value={agentId} onValueChange={setAgentId}>
          <SelectTrigger id="agent-id">
            <SelectValue placeholder="Select agent" />
          </SelectTrigger>
          <SelectContent>
            {agents.map((agent) => (
              <SelectItem key={agent.id} value={String(agent.id)}>{agent.name}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div>
        <Label htmlFor="reason">Reason</Label>
        <Input
          id="reason"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder="Why are you assigning this lead?"
          required
        />
      </div>

      <div className="flex justify-end gap-2 pt-2">
        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
        <Button type="submit">{mode === 'assign' ? 'Assign' : 'Reassign'}</Button>
      </div>
    </form>
  );
}
