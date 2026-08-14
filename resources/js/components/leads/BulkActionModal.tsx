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

interface BulkActionModalProps {
  isOpen: boolean;
  onClose: () => void;
  selectedLeadIds: number[];
  action: 'recycle' | 'archive';
  endpoint: string;
}

const COPY = {
  recycle: {
    title: 'Recycle',
    description:
      'Return the selected leads to the pool as AVAILABLE, closing any active call cycle and freeing agent capacity.',
    confirmLabel: 'Recycle',
    submittingLabel: 'Recycling...',
  },
  archive: {
    title: 'Archive',
    description:
      'Mark the selected leads as EXHAUSTED, removing them from active circulation. This can be undone later via the Revive action.',
    confirmLabel: 'Archive',
    submittingLabel: 'Archiving...',
  },
};

export function BulkActionModal({
  isOpen,
  onClose,
  selectedLeadIds,
  action,
  endpoint,
}: BulkActionModalProps) {
  const [reason, setReason] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const copy = COPY[action];

  const handleSubmit = () => {
    setIsSubmitting(true);

    router.post(
      endpoint,
      {
        lead_ids: selectedLeadIds,
        reason: reason.trim() || undefined,
      },
      {
        onSuccess: () => {
          onClose();
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
          <DialogTitle>
            {copy.title} {selectedLeadIds.length} Leads
          </DialogTitle>
          <DialogDescription>{copy.description}</DialogDescription>
        </DialogHeader>

        <div className="space-y-2 py-2">
          <Label>Reason (optional)</Label>
          <Textarea
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="Add a note for the audit trail..."
            maxLength={500}
          />
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={handleSubmit} disabled={isSubmitting}>
            {isSubmitting
              ? copy.submittingLabel
              : `${copy.confirmLabel} ${selectedLeadIds.length} Leads`}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
