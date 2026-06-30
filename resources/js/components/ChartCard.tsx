import { useState, useEffect } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  AreaChart,
  Area,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts';
import { cn } from '@/lib/utils';

interface ChartCardProps {
  title: string;
  data: Array<Record<string, string | number>>;
  type?: 'area' | 'bar';
  dataKey: string;
  xKey: string;
  color?: string;
  secondaryDataKey?: string;
  className?: string;
  height?: number;
}

export function ChartCard({
  title,
  data,
  type = 'area',
  dataKey,
  xKey,
  color = 'hsl(var(--chart-1))',
  secondaryDataKey,
  className,
  height = 200,
}: ChartCardProps) {
  const [mounted, setMounted] = useState(false);
  useEffect(() => setMounted(true), []);
  if (!mounted)
    return (
      <Card className={cn(className)}>
        <CardHeader className="pb-2">
          <CardTitle className="text-sm font-medium">{title}</CardTitle>
        </CardHeader>
        <CardContent>
          <div style={{ height }} className="animate-pulse bg-muted rounded" />
        </CardContent>
      </Card>
    );
  return (
    <Card className={cn(className)}>
      <CardHeader className="pb-2">
        <CardTitle className="text-sm font-medium">{title}</CardTitle>
      </CardHeader>
      <CardContent>
        <ResponsiveContainer width="100%" height={height}>
          {type === 'area' ? (
            <AreaChart data={data} margin={{ top: 5, right: 5, bottom: 0, left: -20 }}>
              <defs>
                <linearGradient id={`grad-${dataKey}`} x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor={color} stopOpacity={0.3} />
                  <stop offset="95%" stopColor={color} stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid
                strokeDasharray="3 3"
                vertical={false}
                stroke="hsl(var(--chart-grid))"
              />
              <XAxis
                dataKey={xKey}
                tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
                axisLine={false}
                tickLine={false}
              />
              <YAxis
                tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
                axisLine={false}
                tickLine={false}
              />
              <Tooltip
                contentStyle={{
                  borderRadius: 12,
                  border: '1px solid hsl(var(--border))',
                  background: 'hsl(var(--card))',
                  fontSize: 12,
                  boxShadow: 'var(--shadow-md)',
                }}
              />
              <Area
                type="monotone"
                dataKey={dataKey}
                stroke={color}
                fill={`url(#grad-${dataKey})`}
                strokeWidth={2}
              />
              {secondaryDataKey && (
                <Area
                  type="monotone"
                  dataKey={secondaryDataKey}
                  stroke="hsl(var(--chart-2))"
                  fill="none"
                  strokeWidth={2}
                  strokeDasharray="4 4"
                />
              )}
            </AreaChart>
          ) : (
            <BarChart data={data} margin={{ top: 5, right: 5, bottom: 0, left: -20 }}>
              <CartesianGrid
                strokeDasharray="3 3"
                vertical={false}
                stroke="hsl(var(--chart-grid))"
              />
              <XAxis
                dataKey={xKey}
                tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
                axisLine={false}
                tickLine={false}
              />
              <YAxis
                tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
                axisLine={false}
                tickLine={false}
              />
              <Tooltip
                contentStyle={{
                  borderRadius: 12,
                  border: '1px solid hsl(var(--border))',
                  background: 'hsl(var(--card))',
                  fontSize: 12,
                  boxShadow: 'var(--shadow-md)',
                }}
              />
              <Bar dataKey={dataKey} fill={color} radius={[6, 6, 0, 0]} />
              {secondaryDataKey && (
                <Bar dataKey={secondaryDataKey} fill="hsl(var(--chart-2))" radius={[6, 6, 0, 0]} />
              )}
            </BarChart>
          )}
        </ResponsiveContainer>
      </CardContent>
    </Card>
  );
}
