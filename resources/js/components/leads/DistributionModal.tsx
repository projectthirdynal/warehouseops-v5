import { useState } from 'react';
import { router } from '@inertiajs/react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Input } from '@/components/ui/input';

interface Agent {
  id: number;
  name: string;
  active_leads: number;
  max_active_cycles: number;
}

interface DistributionModalProps {
  isOpen: boolean;
  onClose: () => void;
  selectedLeadIds: number[];
  agents: Agent[];
}

export function DistributionModal({
  isOpen,
  onClose,
  selectedLeadIds,
  agents,
}: DistributionModalProps) {
  const [method, setMethod] = useState<'equal' | 'custom'>('equal');
  const [selectedAgents, setSelectedAgents] = useState<number[]>([]);
  const [customDistribution, setCustomDistribution] = useState<Record<number, number>>({});
  const [isSubmitting, setIsSubmitting] = useState(false);

  const availableAgents = agents.filter(
    (a) => a.active_leads < a.max_active_cycles
  );

  const toggleAgent = (agentId: number) => {
    setSelectedAgents((prev) =>
      prev.includes(agentId)
        ? prev.filter((id) => id !== agentId)
        : [...prev, agentId]
    );
  };

  const perAgentCount =
    method === 'equal' && selectedAgents.length > 0
      ? Math.floor(selectedLeadIds.length / selectedAgents.length)
      : 0;

  const totalCustom = Object.values(customDistribution).reduce((a, b) => a + b, 0);

  const handleSubmit = () => {
    setIsSubmitting(true);

    const data: Record<string, unknown> = {
      lead_ids: selectedLeadIds,
      method,
    };

    if (method === 'equal') {
      data.agent_ids = selectedAgents;
    } else {
      data.distribution = customDistribution;
    }

    router.post('/lead-pool/distribute', data as any, {
      onSuccess: () => {
        onClose();
        setSelectedAgents([]);
        setCustomDistribution({});
      },
      onFinish: () => setIsSubmitting(false),
    });
  };

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Distribute {selectedLeadIds.length} Leads</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-4">
          <RadioGroup
            value={method}
            onValueChange={(v) => setMethod(v as 'equal' | 'custom')}
          >
            <div className="flex items-center space-x-2">
              <RadioGroupItem value="equal" id="equal" />
              <Label htmlFor="equal">Equal split</Label>
            </div>
            <div className="flex items-center space-x-2">
              <RadioGroupItem value="custom" id="custom" />
              <Label htmlFor="custom">Custom per agent</Label>
            </div>
          </RadioGroup>

          <div className="border rounded-lg p-3 max-h-64 overflow-y-auto">
            <Label className="text-sm font-medium mb-2 block">Select Agents</Label>
            {availableAgents.map((agent) => (
              <div
                key={agent.id}
                className="flex items-center justify-between py-2 border-b last:border-0"
              >
                <div className="flex items-center gap-2">
                  <Checkbox
                    checked={
                      method === 'equal'
                        ? selectedAgents.includes(agent.id)
                        : (customDistribution[agent.id] || 0) > 0
                    }
                    onCheckedChange={() => {
                      if (method === 'equal') {
                        toggleAgent(agent.id);
                      }
                    }}
                  />
                  <span>{agent.name}</span>
                  <span className="text-sm text-muted-foreground">
                    ({agent.active_leads}/{agent.max_active_cycles})
                  </span>
                </div>
                {method === 'custom' && (
                  <Input
                    type="number"
                    min={0}
                    max={selectedLeadIds.length}
                    className="w-20"
                    value={customDistribution[agent.id] || ''}
                    onChange={(e) =>
                      setCustomDistribution((prev) => ({
                        ...prev,
                        [agent.id]: parseInt(e.target.value) || 0,
                      }))
                    }
                  />
                )}
              </div>
            ))}
          </div>

          <div className="bg-muted p-3 rounded-lg text-sm">
            {method === 'equal' ? (
              <p>
                {selectedLeadIds.length} leads / {selectedAgents.length || '?'} agents
                = <strong>{perAgentCount || '?'} each</strong>
              </p>
            ) : (
              <p>
                Total assigned: <strong>{totalCustom}</strong> / {selectedLeadIds.length}
              </p>
            )}
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={
              isSubmitting ||
              (method === 'equal' && selectedAgents.length === 0) ||
              (method === 'custom' && totalCustom === 0)
            }
          >
            {isSubmitting ? 'Distributing...' : 'Distribute'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
