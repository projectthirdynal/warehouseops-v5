import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Activity, UserCheck, UserX, Shield, Lock } from 'lucide-react';

import type { ActivityItem } from '../types';
import { ACTION_ICONS, ACTION_LABELS } from '../constants';

const ICON_MAP: Record<string, React.ElementType> = {
  UserCheck,
  UserX,
  Shield,
  Lock,
};

interface Props {
  logs: ActivityItem[];
}

export default function ActivityFeed({ logs }: Props) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Activity className="h-4 w-4 text-muted-foreground" />
          Recent Activity
        </CardTitle>
      </CardHeader>
      <CardContent className="p-0">
        <ScrollArea className="h-[500px]">
          {logs.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-16">
              <Activity className="h-10 w-10 text-muted-foreground/40" />
              <p className="mt-3 text-muted-foreground">No activity recorded yet</p>
            </div>
          ) : (
            <div className="divide-y">
              {logs.map(log => {
                const iconName = ACTION_ICONS[log.action] ?? 'Activity';
                const Icon = ICON_MAP[iconName] ?? Activity;
                const label = ACTION_LABELS[log.action] ?? log.action;
                return (
                  <div key={log.id} className="flex items-start gap-3 px-4 py-3 hover:bg-muted/50">
                    <div className="mt-0.5 flex h-8 w-8 items-center justify-center rounded-full bg-primary/10">
                      <Icon className="h-4 w-4 text-primary" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm">
                        <span className="font-medium">{log.user?.name ?? 'System'}</span>
                        {' '}
                        <span className="text-muted-foreground">{label}</span>
                      </p>
                      {log.metadata && (
                        <p className="mt-0.5 text-xs text-muted-foreground truncate">
                          {JSON.stringify(log.metadata).slice(0, 120)}
                        </p>
                      )}
                    </div>
                    <time className="text-xs text-muted-foreground whitespace-nowrap">
                      {new Date(log.created_at).toLocaleString()}
                    </time>
                  </div>
                );
              })}
            </div>
          )}
        </ScrollArea>
      </CardContent>
    </Card>
  );
}
