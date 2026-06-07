import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { useToast } from '@/hooks/use-toast';

interface Props {
  rule?: {
    id: number;
    name: string;
    strategy: string;
    priority: number;
    is_active: boolean;
  } | null;
  onSuccess: () => void;
}

const strategies = [
  { value: 'round_robin', label: 'Round Robin' },
  { value: 'weighted', label: 'Weighted' },
  { value: 'skill_match', label: 'Skill Match' },
  { value: 'territory', label: 'Territory' },
  { value: 'hybrid', label: 'Hybrid' },
];

export default function DistributionRuleForm({ rule, onSuccess }: Props) {
  const toast = useToast();
  const [name, setName] = useState(rule?.name ?? '');
  const [strategy, setStrategy] = useState(rule?.strategy ?? 'hybrid');
  const [priority, setPriority] = useState(String(rule?.priority ?? 0));
  const [isActive, setIsActive] = useState(rule?.is_active ?? true);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    const payload = {
      name,
      strategy,
      priority: parseInt(priority, 10),
      is_active: isActive,
      filters: {},
      weight_formula: {
        w_perf: 0.30,
        w_avail: 0.25,
        w_skill: 0.20,
        w_reg: 0.15,
        w_load: 0.05,
        w_time: 0.05,
      },
    };

    if (rule) {
      router.patch(`/distribution/rules/${rule.id}`, payload, {
        onSuccess: () => { toast.success('Rule updated'); onSuccess(); },
        onError: () => toast.error('Failed to update rule'),
      });
    } else {
      router.post('/distribution/rules', payload, {
        onSuccess: () => { toast.success('Rule created'); onSuccess(); },
        onError: () => toast.error('Failed to create rule'),
      });
    }
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div>
        <Label htmlFor="rule-name">Name</Label>
        <Input
          id="rule-name"
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="e.g. High-value leads"
          required
        />
      </div>

      <div>
        <Label htmlFor="rule-strategy">Strategy</Label>
        <Select value={strategy} onValueChange={setStrategy}>
          <SelectTrigger id="rule-strategy">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {strategies.map((s) => (
              <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div>
        <Label htmlFor="rule-priority">Priority (lower = evaluated first)</Label>
        <Input
          id="rule-priority"
          type="number"
          min={0}
          value={priority}
          onChange={(e) => setPriority(e.target.value)}
          required
        />
      </div>

      <div className="flex items-center gap-2">
        <input
          id="rule-active"
          type="checkbox"
          checked={isActive}
          onChange={(e) => setIsActive(e.target.checked)}
          className="h-4 w-4 rounded border-gray-300"
        />
        <Label htmlFor="rule-active" className="text-sm font-normal">Active</Label>
      </div>

      <div className="flex justify-end gap-2 pt-2">
        <Button type="submit">{rule ? 'Update' : 'Create'} Rule</Button>
      </div>
    </form>
  );
}
