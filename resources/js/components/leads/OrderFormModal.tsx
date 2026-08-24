import { useState, useEffect, useRef } from 'react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import {
  Package,
  MapPin,
  Calculator,
  Loader2,
  Truck,
  AlertTriangle,
  Cloud,
  CloudRain,
  Sun,
  CloudSun,
  CloudDrizzle,
  CloudLightning,
  Navigation,
} from 'lucide-react';
import type { AgentLead } from '@/types/lead-pool';
import { AddressCombobox } from '@/components/agent/AddressCombobox';
import { PriceRemarkCombobox } from '@/components/leads/PriceRemarkCombobox';

interface EtaData {
  eta_days: number;
  eta_date: string;
  zone: string;
  island: string;
  weather_adjusted: boolean;
  weather_condition: string | null;
  source: string;
}

interface WeatherData {
  available: boolean;
  city?: string;
  temperature?: number;
  feels_like?: number;
  humidity?: number;
  condition?: string;
  weather_code?: number;
  is_raining?: boolean;
  forecast?: Array<{
    date: string;
    label: string;
    condition: string;
    weather_code: number;
    temp_max: number;
    temp_min: number;
    precip_prob: number;
  }>;
}

interface OrderFormModalProps {
  lead: AgentLead;
  isOpen: boolean;
  onClose: () => void;
  onSuccess?: () => void;
  remarks: string;
}

function getWeatherIcon(code: number) {
  if (code === 0) return Sun;
  if (code <= 2) return CloudSun;
  if (code === 3) return Cloud;
  if (code >= 45 && code <= 48) return Cloud;
  if (code >= 51 && code <= 57) return CloudDrizzle;
  if (code >= 61 && code <= 67) return CloudRain;
  if (code >= 71 && code <= 77) return Cloud;
  if (code >= 80 && code <= 82) return CloudRain;
  if (code >= 95) return CloudLightning;
  return Cloud;
}

function parseMinPrice(priceKey: string): number {
  const matches = Array.from(priceKey.matchAll(/\d+(?:\.\d+)?/g), (m) => Number(m[0]));
  if (matches.length === 0) return 0;
  return Math.min(...matches);
}

export function OrderFormModal({ lead, isOpen, onClose, onSuccess, remarks }: OrderFormModalProps) {
  const [quantity, setQuantity] = useState(1);
  const [productName, setProductName] = useState(lead.product_name || '');
  const [unitPrice, setUnitPrice] = useState(Number(lead.amount ?? 0));
  const [receiverName, setReceiverName] = useState(lead.name || '');
  const [receiverPhone, setReceiverPhone] = useState(lead.phone || '');
  const [receiverAddress, setReceiverAddress] = useState(lead.address || lead.street || '');
  const [city, setCity] = useState(lead.city || '');
  const [state, setState] = useState(lead.state || '');
  const [barangay, setBarangay] = useState(lead.barangay || '');
  const [postalCode, setPostalCode] = useState(lead.postal_code || '');
  const [landmark, setLandmark] = useState(lead.customer?.landmark || '');
  const [notes, setNotes] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  // ETA + Weather state
  const [eta, setEta] = useState<EtaData | null>(null);
  const [isLoadingEta, setIsLoadingEta] = useState(false);
  const [weather, setWeather] = useState<WeatherData | null>(null);
  const [isLoadingWeather, setIsLoadingWeather] = useState(false);
  const etaAbortRef = useRef<AbortController | null>(null);
  const weatherAbortRef = useRef<AbortController | null>(null);

  const subtotal = quantity * unitPrice;

  // Fetch ETA + weather when address changes (debounced)
  useEffect(() => {
    if (!isOpen || (!state && !city)) {
      setEta(null);
      setWeather(null);
      return;
    }

    const timer = setTimeout(() => {
      // ETA
      if (etaAbortRef.current) etaAbortRef.current.abort();
      const etaController = new AbortController();
      etaAbortRef.current = etaController;
      setIsLoadingEta(true);
      const etaParams = new URLSearchParams();
      if (state) etaParams.set('province', state);
      if (city) etaParams.set('city', city);
      if (barangay) etaParams.set('barangay', barangay);
      fetch(`/api/agent/delivery-eta?${etaParams.toString()}`, { signal: etaController.signal })
        .then((r) => r.json())
        .then((data: EtaData) => setEta(data))
        .catch((err) => {
          if (err.name !== 'AbortError') setEta(null);
        })
        .finally(() => setIsLoadingEta(false));

      // Weather
      if (weatherAbortRef.current) weatherAbortRef.current.abort();
      const weatherController = new AbortController();
      weatherAbortRef.current = weatherController;
      setIsLoadingWeather(true);
      const weatherParams = new URLSearchParams();
      if (city) weatherParams.set('city', `${city}, ${state}`);
      fetch(`/api/agent/weather?${weatherParams.toString()}`, { signal: weatherController.signal })
        .then((r) => r.json())
        .then((data: WeatherData) => setWeather(data))
        .catch((err) => {
          if (err.name !== 'AbortError') setWeather(null);
        })
        .finally(() => setIsLoadingWeather(false));
    }, 400);

    return () => clearTimeout(timer);
  }, [isOpen, state, city, barangay]);

  const handleSubmit = async () => {
    setIsSubmitting(true);

    const data: Record<string, unknown> = {
      outcome: 'ORDERED',
      remarks: remarks || undefined,
      quantity,
      custom_product_name: productName,
      custom_unit_price: unitPrice,
      receiver_name: receiverName,
      receiver_phone: receiverPhone,
      receiver_address: receiverAddress,
      city,
      state,
      barangay,
      postal_code: postalCode || undefined,
      landmark: landmark || undefined,
      notes: notes || undefined,
    };

    try {
      const response = await fetch(`/api/agent/leads/${lead.id}/outcome`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        const err = await response.json().catch(() => ({}));
        alert(err.message || 'Failed to create order');
        return;
      }

      onSuccess?.();
      onClose();
      window.location.reload();
    } catch {
      alert('Failed to create order. Please try again.');
    } finally {
      setIsSubmitting(false);
    }
  };

  // Build map URL (OpenStreetMap embed)
  const mapQuery = [receiverAddress, barangay, city, state].filter(Boolean).join(', ');
  const mapSrc = `https://www.openstreetmap.org/export/embed.html?bbox=120.0,14.0,122.0,15.0&layer=mapnik&marker=14.5995,120.9842`;

  const etaDate = eta ? new Date(eta.eta_date) : null;
  const etaDateStr = etaDate?.toLocaleDateString('en-PH', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
  });

  const CurrentWeatherIcon =
    weather?.weather_code !== undefined ? getWeatherIcon(weather.weather_code) : null;

  return (
    <Dialog open={isOpen} onOpenChange={onClose} modal={false}>
      <DialogContent className="sm:max-w-5xl max-h-[92vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Package className="h-5 w-5" />
            Create Order
          </DialogTitle>
          <DialogDescription>
            Customize the delivery address, quantity, and apply promos for {lead.name}.
          </DialogDescription>
        </DialogHeader>

        <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4">
          {/* LEFT COLUMN: Form */}
          <div className="space-y-5 py-2">
            {/* Delivery Address Section */}
            <div className="space-y-3">
              <h3 className="text-sm font-semibold flex items-center gap-1">
                <MapPin className="h-4 w-4" />
                Delivery Address
              </h3>
              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <Label htmlFor="receiver_name" className="text-xs">
                    Receiver Name
                  </Label>
                  <Input
                    id="receiver_name"
                    value={receiverName}
                    onChange={(e) => setReceiverName(e.target.value)}
                  />
                </div>
                <div className="space-y-1">
                  <Label htmlFor="receiver_phone" className="text-xs">
                    Receiver Phone
                  </Label>
                  <Input
                    id="receiver_phone"
                    value={receiverPhone}
                    onChange={(e) => setReceiverPhone(e.target.value)}
                  />
                </div>
              </div>
              <div className="space-y-1">
                <Label htmlFor="receiver_address" className="text-xs">
                  Street Address
                </Label>
                <Input
                  id="receiver_address"
                  value={receiverAddress}
                  onChange={(e) => setReceiverAddress(e.target.value)}
                  placeholder="House #, Street, Purok..."
                />
              </div>

              {/* Cascading searchable dropdowns */}
              <div className="space-y-1">
                <Label className="text-xs">Province</Label>
                <AddressCombobox
                  value={state}
                  onChange={(v) => {
                    const changed = v.toLowerCase() !== state.toLowerCase();
                    setState(v);
                    if (changed) {
                      setCity('');
                      setBarangay('');
                    }
                  }}
                  placeholder="Select province"
                  endpoint="/api/agent/address/provinces"
                  dataKey="provinces"
                />
              </div>
              <div className="space-y-1">
                <Label className="text-xs">City / Municipality</Label>
                <AddressCombobox
                  value={city}
                  onChange={(v) => {
                    const changed = v.toLowerCase() !== city.toLowerCase();
                    setCity(v);
                    if (changed) {
                      setBarangay('');
                    }
                  }}
                  placeholder={state ? 'Select city' : 'Select province first'}
                  disabled={!state}
                  endpoint="/api/agent/address/cities"
                  dataKey="cities"
                  extraParams={{ province: state }}
                />
              </div>
              <div className="space-y-1">
                <Label className="text-xs">Barangay</Label>
                <AddressCombobox
                  value={barangay}
                  onChange={(v) => setBarangay(v)}
                  placeholder={city ? 'Select barangay' : 'Select city first'}
                  disabled={!city}
                  endpoint="/api/agent/address/barangays"
                  dataKey="barangays"
                  extraParams={{ province: state, city }}
                  showShippingDays
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <Label htmlFor="postal_code" className="text-xs">
                    Postal Code <span className="text-muted-foreground">(optional)</span>
                  </Label>
                  <Input
                    id="postal_code"
                    value={postalCode}
                    onChange={(e) => setPostalCode(e.target.value)}
                    placeholder="e.g. 1000"
                  />
                </div>
                <div className="space-y-1">
                  <Label htmlFor="landmark" className="text-xs">
                    Landmark
                  </Label>
                  <Input
                    id="landmark"
                    value={landmark}
                    onChange={(e) => setLandmark(e.target.value)}
                    placeholder="Near..."
                  />
                </div>
              </div>
            </div>

            {/* Order Items Section */}
            <div className="space-y-3 border-t pt-4">
              <h3 className="text-sm font-semibold flex items-center gap-1">
                <Package className="h-4 w-4" />
                Order Items
              </h3>
              <div className="bg-muted/50 rounded-lg p-3 space-y-3">
                <div className="space-y-1">
                  <Label className="text-xs">Product</Label>
                  <PriceRemarkCombobox
                    value={productName}
                    onSelect={(row) => {
                      setProductName(row.remarks);
                      const minPrice = parseMinPrice(row.price_key);
                      if (minPrice > 0) setUnitPrice(minPrice);
                    }}
                    onClear={() => {
                      setProductName(lead.product_name || '');
                      setUnitPrice(Number(lead.amount ?? 0));
                    }}
                    placeholder={lead.product_name || 'Select bundle...'}
                  />
                  {lead.product_brand && (
                    <div className="text-xs text-muted-foreground">
                      Original brand: {lead.product_brand}
                    </div>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1">
                    <Label htmlFor="custom_unit_price" className="text-xs">
                      Unit Price
                    </Label>
                    <Input
                      id="custom_unit_price"
                      type="number"
                      min={0}
                      step="0.01"
                      value={unitPrice}
                      onChange={(e) => setUnitPrice(Math.max(0, Number(e.target.value) || 0))}
                      className="text-right"
                    />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor="quantity" className="text-xs">
                      Qty
                    </Label>
                    <Input
                      id="quantity"
                      type="number"
                      min={1}
                      max={99}
                      value={quantity}
                      onChange={(e) => setQuantity(Math.max(1, parseInt(e.target.value) || 1))}
                      className="text-center"
                    />
                  </div>
                </div>
                <div className="flex justify-between text-sm font-medium pt-1 border-t">
                  <span>Subtotal</span>
                  <span>₱{subtotal.toFixed(2)}</span>
                </div>
              </div>
            </div>

            {/* Order Summary */}
            <div className="space-y-3 border-t pt-4">
              <h3 className="text-sm font-semibold flex items-center gap-1">
                <Calculator className="h-4 w-4" />
                Order Summary
              </h3>
              <div className="bg-muted/50 rounded-lg p-3 space-y-2 text-sm">
                <div className="flex justify-between">
                  <span>
                    Subtotal ({quantity} x ₱{unitPrice.toFixed(2)})
                  </span>
                  <span>₱{subtotal.toFixed(2)}</span>
                </div>
                <div className="flex justify-between font-bold text-base border-t pt-2">
                  <span>Total COD</span>
                  <span>₱{subtotal.toFixed(2)}</span>
                </div>
              </div>
            </div>

            {/* Notes */}
            <div className="space-y-2">
              <Label htmlFor="order_notes">Order Notes (optional)</Label>
              <Textarea
                id="order_notes"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                placeholder="Special delivery instructions, customer requests..."
                rows={2}
              />
            </div>
          </div>

          {/* RIGHT COLUMN: Map + ETA + Weather side panel */}
          <div className="space-y-4 lg:sticky lg:top-0 lg:self-start">
            {/* Map */}
            <div className="space-y-2">
              <h3 className="text-sm font-semibold flex items-center gap-1">
                <Navigation className="h-4 w-4" />
                Location Map
              </h3>
              <div className="rounded-lg overflow-hidden border bg-muted/30">
                <iframe
                  title="Delivery Location Map"
                  src={mapSrc}
                  className="w-full h-[200px] border-0"
                  loading="lazy"
                />
                <div className="p-2 text-xs text-muted-foreground truncate">
                  {mapQuery || 'Select address to view map'}
                </div>
              </div>
            </div>

            <Separator />

            {/* Delivery ETA */}
            <div className="space-y-2">
              <h3 className="text-sm font-semibold flex items-center gap-1">
                <Truck className="h-4 w-4" />
                Delivery ETA
              </h3>
              {isLoadingEta ? (
                <div className="flex items-center gap-2 text-sm text-muted-foreground py-2">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  Calculating...
                </div>
              ) : eta ? (
                <Card>
                  <CardContent className="p-3 space-y-2">
                    <div className="flex items-center justify-between">
                      <span className="text-2xl font-bold text-blue-600">{eta.eta_days} days</span>
                      {eta.weather_adjusted && (
                        <Badge
                          variant="outline"
                          className="border-orange-300 text-orange-700 text-xs"
                        >
                          <AlertTriangle className="h-3 w-3 mr-1" />
                          +1 weather
                        </Badge>
                      )}
                    </div>
                    <div className="text-sm text-muted-foreground">
                      Est. delivery:{' '}
                      <span className="font-medium text-foreground">{etaDateStr}</span>
                    </div>
                    <div className="flex items-center gap-2 text-xs">
                      <Badge variant="secondary" className="text-xs">
                        {eta.island}
                      </Badge>
                      <Badge variant="outline" className="text-xs">
                        {eta.zone}
                      </Badge>
                      {eta.source === 'courier_table' ? (
                        <Badge
                          variant="outline"
                          className="text-xs text-green-600 border-green-300"
                        >
                          Exact match
                        </Badge>
                      ) : (
                        <Badge
                          variant="outline"
                          className="text-xs text-amber-600 border-amber-300"
                        >
                          Estimated
                        </Badge>
                      )}
                    </div>
                    {eta.weather_condition && (
                      <div className="text-xs text-orange-600 flex items-center gap-1">
                        <AlertTriangle className="h-3 w-3" />
                        {eta.weather_condition} — may delay delivery
                      </div>
                    )}
                  </CardContent>
                </Card>
              ) : (
                <div className="text-sm text-muted-foreground py-2">
                  Select province and city to estimate delivery.
                </div>
              )}
            </div>

            <Separator />

            {/* Weather Forecast */}
            <div className="space-y-2">
              <h3 className="text-sm font-semibold flex items-center gap-1">
                <Cloud className="h-4 w-4" />
                Weather Forecast
              </h3>
              {isLoadingWeather ? (
                <div className="flex items-center gap-2 text-sm text-muted-foreground py-2">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  Loading weather...
                </div>
              ) : weather && weather.available ? (
                <Card>
                  <CardContent className="p-3 space-y-3">
                    <div className="flex items-center gap-3">
                      {CurrentWeatherIcon && (
                        <CurrentWeatherIcon className="h-8 w-8 text-blue-500" />
                      )}
                      <div>
                        <div className="text-xl font-bold">{weather.temperature}°C</div>
                        <div className="text-xs text-muted-foreground">{weather.condition}</div>
                      </div>
                      {weather.humidity !== undefined && (
                        <div className="ml-auto text-xs text-muted-foreground">
                          {weather.humidity}% humidity
                        </div>
                      )}
                    </div>
                    {weather.forecast && weather.forecast.length > 0 && (
                      <div className="grid grid-cols-3 gap-1.5">
                        {weather.forecast.map((day) => {
                          const Icon = getWeatherIcon(day.weather_code);
                          return (
                            <div key={day.date} className="text-center p-1.5 rounded bg-muted/50">
                              <div className="text-[10px] font-medium">{day.label}</div>
                              <Icon className="h-4 w-4 mx-auto my-0.5 text-blue-500" />
                              <div className="text-[10px]">
                                <span className="font-medium">{day.temp_max}°</span>
                                <span className="text-muted-foreground">/{day.temp_min}°</span>
                              </div>
                              {day.precip_prob > 0 && (
                                <div className="text-[10px] text-blue-500">{day.precip_prob}%</div>
                              )}
                            </div>
                          );
                        })}
                      </div>
                    )}
                  </CardContent>
                </Card>
              ) : (
                <div className="text-sm text-muted-foreground py-2">
                  Weather unavailable for this location.
                </div>
              )}
            </div>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={isSubmitting || !receiverName || !receiverPhone || !state || !city}
          >
            {isSubmitting ? (
              <>
                <Loader2 className="h-4 w-4 mr-1 animate-spin" />
                Creating Order...
              </>
            ) : (
              'Create Order'
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
