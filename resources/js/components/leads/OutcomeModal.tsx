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
import { Textarea } from '@/components/ui/textarea';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import type { LeadOutcome, OutcomeFormData } from '@/types/lead-pool';

interface OutcomeModalProps {
  leadId: number;
  isOpen: boolean;
  onClose: () => void;
  onSuccess?: () => void;
}

const outcomes: { value: LeadOutcome; label: string; description: string }[] = [
  { value: 'NO_ANSWER', label: 'No Answer', description: "Couldn't reach the customer" },
  { value: 'CALLBACK', label: 'Callback', description: 'Customer requested callback' },
  { value: 'INTERESTED', label: 'Interested', description: 'Warm lead, needs follow-up' },
  { value: 'ORDERED', label: 'Ordered/Sold', description: 'Successful sale!' },
  { value: 'NOT_INTERESTED', label: 'Not Interested', description: 'Customer declined' },
  { value: 'WRONG_NUMBER', label: 'Wrong Number', description: 'Invalid contact' },
];

export function OutcomeModal({ leadId, isOpen, onClose, onSuccess }: OutcomeModalProps) {
  const [selectedOutcome, setSelectedOutcome] = useState<LeadOutcome | null>(null);
  const [remarks, setRemarks] = useState('');
  const [callbackDate, setCallbackDate] = useState('');
  const [callbackTime, setCallbackTime] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleSubmit = async () => {
    if (!selectedOutcome) return;

    setIsSubmitting(true);

    const data: OutcomeFormData = {
      outcome: selectedOutcome,
      remarks: remarks || undefined,
    };

    if (selectedOutcome === 'CALLBACK' && callbackDate && callbackTime) {
      data.callback_at = `${callbackDate}T${callbackTime}:00`;
    }

    try {
      const response = await fetch(`/api/agent/leads/${leadId}/outcome`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        throw new Error('Failed to record outcome');
      }

      // Reset form
      setSelectedOutcome(null);
      setRemarks('');
      setCallbackDate('');
      setCallbackTime('');

      onSuccess?.();
      onClose();

      // Refresh page data
      router.reload();
    } catch (error) {
      console.error('Failed to record outcome:', error);
      alert('Failed to record outcome. Please try again.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Record Call Outcome</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-4">
          <RadioGroup
            value={selectedOutcome || ''}
            onValueChange={(value) => setSelectedOutcome(value as LeadOutcome)}
          >
            {outcomes.map((outcome) => (
              <div key={outcome.value} className="flex items-start space-x-3 p-2 rounded hover:bg-muted">
                <RadioGroupItem value={outcome.value} id={outcome.value} />
                <Label htmlFor={outcome.value} className="cursor-pointer flex-1">
                  <div className="font-medium">{outcome.label}</div>
                  <div className="text-sm text-muted-foreground">{outcome.description}</div>
                </Label>
              </div>
            ))}
          </RadioGroup>

          {selectedOutcome === 'CALLBACK' && (
            <div className="space-y-2 p-3 bg-muted rounded-lg">
              <Label>Schedule Callback</Label>
              <div className="flex gap-2">
                <input
                  type="date"
                  value={callbackDate}
                  onChange={(e) => setCallbackDate(e.target.value)}
                  min={new Date().toISOString().split('T')[0]}
                  className="flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
                <input
                  type="time"
                  value={callbackTime}
                  onChange={(e) => setCallbackTime(e.target.value)}
                  className="flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
              </div>
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="remarks">Remarks (optional)</Label>
            <Textarea
              id="remarks"
              value={remarks}
              onChange={(e) => setRemarks(e.target.value)}
              placeholder="Add notes about this call..."
              rows={3}
            />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={!selectedOutcome || isSubmitting}
          >
            {isSubmitting ? 'Saving...' : 'Save Outcome'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
