import { useState, useEffect } from 'react';
import { Truck, Loader2, AlertTriangle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

interface DeliveryEtaBadgeProps {
  province: string | null;
  city: string | null;
  barangay?: string | null;
}

interface EtaData {
  eta_days: number;
  eta_date: string;
  zone: string;
  island: string;
  weather_adjusted: boolean;
  weather_condition: string | null;
  source: string;
}

export function DeliveryEtaBadge({ province, city, barangay }: DeliveryEtaBadgeProps) {
  const [eta, setEta] = useState<EtaData | null>(null);
  const [isLoading, setIsLoading] = useState(false);

  useEffect(() => {
    if (!province && !city) {
      setEta(null);
      return;
    }

    setIsLoading(true);
    const params = new URLSearchParams();
    if (province) params.set('province', province);
    if (city) params.set('city', city);
    if (barangay) params.set('barangay', barangay);

    fetch(`/api/agent/delivery-eta?${params.toString()}`)
      .then((r) => r.json())
      .then((data) => setEta(data))
      .catch(() => setEta(null))
      .finally(() => setIsLoading(false));
  }, [province, city]);

  if (isLoading) {
    return (
      <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
        <Loader2 className="h-3 w-3 animate-spin" />
        ETA...
      </span>
    );
  }

  if (!eta) return null;

  const etaDate = new Date(eta.eta_date);
  const dateStr = etaDate.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });

  return (
    <Badge
      variant="outline"
      className={`text-xs ${eta.weather_adjusted ? 'border-orange-300 text-orange-700' : 'border-blue-300 text-blue-700'}`}
    >
      <Truck className="h-3 w-3 mr-1" />
      {eta.eta_days}d • {dateStr}
      {eta.weather_adjusted && <AlertTriangle className="h-3 w-3 ml-1 text-orange-500" />}
    </Badge>
  );
}
