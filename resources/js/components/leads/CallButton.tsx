import { useState } from 'react';
import { Phone, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';

interface CallButtonProps {
  leadId: number;
  disabled?: boolean;
  onCallInitiated?: (callCount: number) => void;
}

export function CallButton({ leadId, disabled, onCallInitiated }: CallButtonProps) {
  const [isLoading, setIsLoading] = useState(false);

  const handleCall = async () => {
    setIsLoading(true);

    try {
      const response = await fetch(`/api/agent/leads/${leadId}/call`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
        },
      });

      if (!response.ok) {
        throw new Error('Failed to initiate call');
      }

      const data = await response.json();

      // Open SIP link - MicroSIP will handle it
      window.location.href = data.sip_link;

      if (onCallInitiated) {
        onCallInitiated(data.call_count);
      }
    } catch (error) {
      console.error('Call failed:', error);
      alert('Failed to initiate call. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <Button
      onClick={handleCall}
      disabled={disabled || isLoading}
      className="bg-green-600 hover:bg-green-700"
    >
      {isLoading ? (
        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
      ) : (
        <Phone className="mr-2 h-4 w-4" />
      )}
      Call
    </Button>
  );
}
