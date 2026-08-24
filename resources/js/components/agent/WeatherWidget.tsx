import { useState, useEffect } from 'react';
import {
  Cloud,
  CloudRain,
  Sun,
  CloudSun,
  CloudDrizzle,
  CloudLightning,
  Loader2,
  MapPin,
} from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';

interface ForecastDay {
  date: string;
  label: string;
  condition: string;
  weather_code: number;
  temp_max: number;
  temp_min: number;
  precip_prob: number;
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
  forecast?: ForecastDay[];
}

interface WeatherWidgetProps {
  lat?: number;
  lon?: number;
  city?: string;
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

export function WeatherWidget({ lat, lon, city }: WeatherWidgetProps) {
  const [weather, setWeather] = useState<WeatherData | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    const params = new URLSearchParams();
    if (lat !== undefined) params.set('lat', String(lat));
    if (lon !== undefined) params.set('lon', String(lon));
    if (city) params.set('city', city);

    fetch(`/api/agent/weather?${params.toString()}`)
      .then((r) => r.json())
      .then((data) => setWeather(data))
      .catch(() => setWeather({ available: false }))
      .finally(() => setIsLoading(false));
  }, [lat, lon, city]);

  if (isLoading) {
    return (
      <Card>
        <CardContent className="flex items-center justify-center py-6">
          <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
        </CardContent>
      </Card>
    );
  }

  if (!weather || !weather.available) {
    return (
      <Card>
        <CardContent className="py-4 text-center text-sm text-muted-foreground">
          Weather unavailable
        </CardContent>
      </Card>
    );
  }

  const CurrentIcon = getWeatherIcon(weather.weather_code ?? 0);

  return (
    <Card>
      <CardContent className="p-4">
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-2">
            <MapPin className="h-4 w-4 text-muted-foreground" />
            <span className="text-sm font-medium">{weather.city}</span>
          </div>
        </div>

        <div className="flex items-center gap-3 mb-4">
          <CurrentIcon className="h-10 w-10 text-blue-500" />
          <div>
            <div className="text-2xl font-bold">{weather.temperature}°C</div>
            <div className="text-xs text-muted-foreground">{weather.condition}</div>
          </div>
        </div>

        {weather.forecast && weather.forecast.length > 0 && (
          <div className="grid grid-cols-3 gap-2">
            {weather.forecast.map((day) => {
              const Icon = getWeatherIcon(day.weather_code);
              return (
                <div key={day.date} className="text-center p-2 rounded bg-muted/50">
                  <div className="text-xs font-medium">{day.label}</div>
                  <Icon className="h-5 w-5 mx-auto my-1 text-blue-500" />
                  <div className="text-xs">
                    <span className="font-medium">{day.temp_max}°</span>
                    <span className="text-muted-foreground"> / {day.temp_min}°</span>
                  </div>
                  {day.precip_prob > 0 && (
                    <div className="text-xs text-blue-500">{day.precip_prob}%</div>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
