import { useState } from 'react';
import { router } from '@inertiajs/react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

interface Agent {
  id: number;
  name: string;
  active_leads: number;
  max_active_cycles: number;
}

interface BulkReassignModalProps {
  isOpen: boolean;
  onClose: () => void;
  selectedLeadIds: number[];
  agents: Agent[];
}

export function BulkReassignModal({
  isOpen,
  onClose,
  selectedLeadIds,
  agents,
}: BulkReassignModalProps) {
  const [agentId, setAgentId] = useState<string>('');
  const [reason, setReason] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleSubmit = () => {
    if (!agentId || !reason.trim()) return;

    setIsSubmitting(true);

    router.post(
      '/distribution/bulk-reassign',
      {
        lead_ids: selectedLeadIds,
        agent_id: Number(agentId),
        reason,
      },
      {
        onSuccess: () => {
          onClose();
          setAgentId('');
          setReason('');
        },
        onFinish: () => setIsSubmitting(false),
      }
    );
  };

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Reassign {selectedLeadIds.length} Leads</DialogTitle>
          <DialogDescription>
            Move the selected assigned leads to a different agent. Only leads currently in ASSIGNED
            status will be reassigned.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div className="space-y-2">
            <Label>New Agent</Label>
            <Select value={agentId} onValueChange={setAgentId}>
              <SelectTrigger>
                <SelectValue placeholder="Select an agent" />
              </SelectTrigger>
              <SelectContent>
                {agents.map((agent) => (
                  <SelectItem key={agent.id} value={String(agent.id)}>
                    {agent.name} ({agent.active_leads}/{agent.max_active_cycles} active)
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label>Reason</Label>
            <Textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="Why are these leads being reassigned?"
              maxLength={500}
            />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={handleSubmit} disabled={isSubmitting || !agentId || !reason.trim()}>
            {isSubmitting ? 'Reassigning...' : `Reassign ${selectedLeadIds.length} Leads`}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
