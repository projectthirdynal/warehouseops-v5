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
import { Badge } from '@/components/ui/badge';
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
  max_daily_leads: number;
  product_skills: string[];
}

interface DistributionModalProps {
  isOpen: boolean;
  onClose: () => void;
  selectedLeadIds: number[];
  agents: Agent[];
  productOptions: string[];
}

export function DistributionModal({
  isOpen,
  onClose,
  selectedLeadIds,
  agents,
  productOptions,
}: DistributionModalProps) {
  const [method, setMethod] = useState<'equal' | 'custom'>('equal');
  const [selectedAgents, setSelectedAgents] = useState<number[]>([]);
  const [customDistribution, setCustomDistribution] = useState<Record<number, number>>({});
  const [productFilter, setProductFilter] = useState<string>('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Agents with remaining capacity
  const availableAgents = agents.filter((a) => a.active_leads < a.max_active_cycles);

  // When a product is selected, surface agents who have that skill first
  const sortedAgents = productFilter
    ? [
        ...availableAgents.filter((a) => a.product_skills.includes(productFilter)),
        ...availableAgents.filter((a) => !a.product_skills.includes(productFilter)),
      ]
    : availableAgents;

  const toggleAgent = (agentId: number) => {
    setSelectedAgents((prev) =>
      prev.includes(agentId) ? prev.filter((id) => id !== agentId) : [...prev, agentId]
    );
  };

  const remainingCapacity = (agent: Agent) =>
    Math.max(0, agent.max_daily_leads - agent.active_leads);

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
      ...(productFilter ? { product_filter: productFilter } : {}),
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
        setProductFilter('');
      },
      onFinish: () => setIsSubmitting(false),
    });
  };

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>Distribute {selectedLeadIds.length} Leads</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-2">
          {/* Product filter */}
          {productOptions.length > 0 && (
            <div className="space-y-1">
              <Label className="text-sm font-medium">Filter by Product</Label>
              <Select
                value={productFilter || '_all'}
                onValueChange={(v) => setProductFilter(v === '_all' ? '' : v)}
              >
                <SelectTrigger>
                  <SelectValue placeholder="All products" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="_all">All products</SelectItem>
                  {productOptions.map((p) => (
                    <SelectItem key={p} value={p}>
                      {p}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {productFilter && (
                <p className="text-xs text-muted-foreground">
                  Only leads matching "{productFilter}" will be distributed. Agents with this
                  product skill are shown first.
                </p>
              )}
            </div>
          )}

          {/* Method */}
          <RadioGroup value={method} onValueChange={(v) => setMethod(v as 'equal' | 'custom')}>
            <div className="flex items-center space-x-2">
              <RadioGroupItem value="equal" id="equal" />
              <Label htmlFor="equal">Equal split</Label>
            </div>
            <div className="flex items-center space-x-2">
              <RadioGroupItem value="custom" id="custom" />
              <Label htmlFor="custom">Custom per agent</Label>
            </div>
          </RadioGroup>

          {/* Agent list */}
          <div className="border rounded-lg divide-y max-h-72 overflow-y-auto">
            <div className="px-3 py-2 bg-muted/50 text-xs font-medium text-muted-foreground grid grid-cols-[auto_1fr_auto_auto] gap-2 items-center">
              <span />
              <span>Agent</span>
              <span className="text-right">Capacity</span>
              {method === 'custom' && <span className="text-right w-20">Leads</span>}
            </div>
            {sortedAgents.map((agent) => {
              const hasSkill = productFilter && agent.product_skills.includes(productFilter);
              const cap = remainingCapacity(agent);
              const isSelected =
                method === 'equal'
                  ? selectedAgents.includes(agent.id)
                  : (customDistribution[agent.id] || 0) > 0;

              return (
                <div
                  key={agent.id}
                  className={`flex items-center gap-2 px-3 py-2 ${hasSkill ? 'bg-success/5/50' : ''}`}
                >
                  <Checkbox
                    checked={isSelected}
                    onCheckedChange={() => {
                      if (method === 'equal') toggleAgent(agent.id);
                    }}
                    disabled={method === 'custom'}
                  />
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-1.5 flex-wrap">
                      <span className="text-sm font-medium">{agent.name}</span>
                      {hasSkill && (
                        <Badge variant="default" className="text-[10px] px-1 py-0 h-4 bg-success">
                          Skilled
                        </Badge>
                      )}
                      {agent.product_skills.length > 0 && (
                        <span className="text-[10px] text-muted-foreground truncate">
                          {agent.product_skills.slice(0, 2).join(', ')}
                          {agent.product_skills.length > 2 &&
                            ` +${agent.product_skills.length - 2}`}
                        </span>
                      )}
                    </div>
                  </div>
                  <span className="text-xs text-muted-foreground whitespace-nowrap">
                    {agent.active_leads}/{agent.max_active_cycles} · {cap} free
                  </span>
                  {method === 'custom' && (
                    <Input
                      type="number"
                      min={0}
                      max={Math.min(selectedLeadIds.length, cap)}
                      className="w-20 h-7 text-sm"
                      placeholder="0"
                      value={customDistribution[agent.id] || ''}
                      onChange={(e) => {
                        const val = Math.min(parseInt(e.target.value) || 0, cap);
                        setCustomDistribution((prev) => ({ ...prev, [agent.id]: val }));
                      }}
                    />
                  )}
                </div>
              );
            })}
            {sortedAgents.length === 0 && (
              <p className="text-sm text-muted-foreground text-center py-4">No available agents.</p>
            )}
          </div>

          {/* Summary */}
          <div className="bg-muted p-3 rounded-lg text-sm space-y-1">
            {method === 'equal' ? (
              <p>
                {selectedLeadIds.length} leads ÷ {selectedAgents.length || '?'} agents ={' '}
                <strong>{perAgentCount || '?'} each</strong>
              </p>
            ) : (
              <p>
                Assigned: <strong>{totalCustom}</strong> / {selectedLeadIds.length} leads
                {totalCustom > selectedLeadIds.length && (
                  <span className="ml-2 text-destructive font-medium">⚠ exceeds selection</span>
                )}
              </p>
            )}
            {productFilter && (
              <p className="text-xs text-muted-foreground">
                Product filter active: <strong>{productFilter}</strong>
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
              (method === 'custom' && totalCustom === 0) ||
              (method === 'custom' && totalCustom > selectedLeadIds.length)
            }
          >
            {isSubmitting ? 'Distributing…' : 'Distribute'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
