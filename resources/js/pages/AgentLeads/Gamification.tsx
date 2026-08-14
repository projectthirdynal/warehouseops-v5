import { Head } from '@inertiajs/react';
import {
  Flame,
  Trophy,
  Star,
  Zap,
  CheckCircle,
  MessageSquare,
  MessagesSquare,
  ShoppingBag,
  Gauge,
  Heart,
  Award,
  Target,
  TrendingUp,
  Medal,
  Crown,
  type LucideIcon,
} from 'lucide-react';
import AgentLayout from '@/layouts/AgentLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge as UIBadge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

const ICON_MAP: Record<string, LucideIcon> = {
  MessageSquare,
  MessagesSquare,
  Zap,
  CheckCircle,
  ShoppingBag,
  Star,
  Trophy,
  Flame,
  Gauge,
  Heart,
  Award,
  Medal,
  Crown,
  Target,
};
const COLOR_MAP: Record<string, string> = {
  primary: 'text-primary bg-primary/10',
  success: 'text-success bg-success/10',
  warning: 'text-warning bg-warning/10',
  destructive: 'text-destructive bg-destructive/10',
  info: 'text-info bg-info/10',
};
const CAT_LABELS: Record<string, string> = {
  conversations: 'Conversations',
  resolution: 'Resolution',
  sales: 'Sales',
  streaks: 'Streaks',
  performance: 'Performance',
  sentiment: 'Sentiment',
};

interface BadgeItem {
  id: number;
  name: string;
  description: string;
  icon: string;
  color: string;
  category: string;
  earned?: boolean;
  awarded_at?: string;
}
interface MilestoneItem {
  id: number;
  name: string;
  description: string;
  metric: string;
  target_value: number;
  current_value: number;
  progress_pct: number;
  completed: boolean;
  completed_at: string | null;
}
interface StreakData {
  current: number;
  longest: number;
  last_activity_date: string | null;
  streak_started_at: string | null;
}
interface AgentProfile {
  user_id: number;
  user_name: string;
  user_role: string;
  badges: BadgeItem[];
  available_badges: BadgeItem[];
  streak: StreakData | null;
  milestones: MilestoneItem[];
  total_badges: number;
  total_milestones_completed: number;
}
interface LeaderboardItem {
  user_id: number;
  name: string;
  role: string;
  badge_count: number;
  completed_milestones: number;
  current_streak: number;
}
interface Props {
  profile: AgentProfile;
  leaderboard: LeaderboardItem[];
  settings: Record<string, string | number>;
  agent: { id: number; name: string };
}

const getIcon = (n: string): LucideIcon => ICON_MAP[n] ?? Award;
const getColor = (c: string): string => COLOR_MAP[c] ?? 'text-muted-foreground bg-muted';
const fmtDate = (d: string | null): string =>
  d ? new Date(d).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) : '—';

export default function Gamification({ profile, leaderboard, agent }: Props) {
  const locked = profile.available_badges.filter((b) => !b.earned);
  const inProgress = profile.milestones.filter((m) => !m.completed);
  const grouped = profile.available_badges.reduce<Record<string, BadgeItem[]>>((acc, b) => {
    const cat = b.category || 'other';
    if (!acc[cat]) acc[cat] = [];
    acc[cat].push(b);
    return acc;
  }, {});

  return (
    <AgentLayout>
      <Head title="My Achievements" />
      <div className="space-y-6">
        <div>
          <h1 className="text-xl font-bold font-display tracking-tight">My Achievements</h1>
          <p className="text-muted-foreground">Track your badges, streaks, and milestones</p>
        </div>

        {/* Summary Stats */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Current Streak
              </CardTitle>
              <Flame className="h-4 w-4 text-destructive" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold font-display text-destructive">
                {profile.streak?.current ?? 0}
                <span className="text-sm font-normal text-muted-foreground ml-1">days</span>
              </div>
              <p className="text-xs text-muted-foreground mt-1">
                Best: {profile.streak?.longest ?? 0} days
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Badges Earned
              </CardTitle>
              <Trophy className="h-4 w-4 text-warning" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold font-display text-warning">
                {profile.total_badges}
                <span className="text-sm font-normal text-muted-foreground ml-1">
                  / {profile.available_badges.length}
                </span>
              </div>
              <p className="text-xs text-muted-foreground mt-1">{locked.length} remaining</p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Milestones Done
              </CardTitle>
              <Target className="h-4 w-4 text-success" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold font-display text-success">
                {profile.total_milestones_completed}
                <span className="text-sm font-normal text-muted-foreground ml-1">
                  / {profile.milestones.length}
                </span>
              </div>
              <p className="text-xs text-muted-foreground mt-1">{inProgress.length} in progress</p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Last Active
              </CardTitle>
              <TrendingUp className="h-4 w-4 text-info" />
            </CardHeader>
            <CardContent>
              <div className="text-sm font-bold font-display">
                {fmtDate(profile.streak?.last_activity_date ?? null)}
              </div>
              <p className="text-xs text-muted-foreground mt-1">
                Since: {fmtDate(profile.streak?.streak_started_at ?? null)}
              </p>
            </CardContent>
          </Card>
        </div>

        {/* Streak Banner */}
        {profile.streak && profile.streak.current > 0 && (
          <Card className="border-destructive/30 bg-gradient-to-r from-destructive/5 to-transparent">
            <CardContent className="flex items-center gap-4 py-4">
              <div className="flex h-14 w-14 items-center justify-center rounded-full bg-destructive/10">
                <Flame className="h-7 w-7 text-destructive" />
              </div>
              <div>
                <p className="text-lg font-bold font-display">
                  {profile.streak.current}-Day Streak!
                </p>
                <p className="text-sm text-muted-foreground">
                  {profile.streak.current < profile.streak.longest
                    ? `Your best is ${profile.streak.longest} days — keep going!`
                    : 'New personal record!'}
                </p>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Badges by Category */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Trophy className="h-5 w-5 text-warning" /> Badge Collection
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-5">
            {Object.entries(grouped).map(([cat, badges]) => (
              <div key={cat}>
                <p className="text-sm font-medium mb-3">{CAT_LABELS[cat] ?? cat}</p>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  {badges.map((b) => {
                    const Icon = getIcon(b.icon);
                    return (
                      <div
                        key={b.id}
                        className={`flex items-center gap-3 rounded-lg border p-3 ${b.earned ? '' : 'opacity-50'}`}
                      >
                        <div
                          className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${getColor(b.color)}`}
                        >
                          <Icon className="h-5 w-5" />
                        </div>
                        <div className="min-w-0">
                          <p className="text-sm font-medium truncate">{b.name}</p>
                          <p className="text-xs text-muted-foreground truncate">{b.description}</p>
                          {b.earned && b.awarded_at && (
                            <p className="text-[10px] text-success mt-0.5">
                              Earned {fmtDate(b.awarded_at)}
                            </p>
                          )}
                          {!b.earned && (
                            <p className="text-[10px] text-muted-foreground mt-0.5">Locked</p>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            ))}
          </CardContent>
        </Card>

        {/* Milestones */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Target className="h-5 w-5 text-success" /> Milestones
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {profile.milestones.length > 0 ? (
              profile.milestones.map((m) => (
                <div key={m.id} className="space-y-1.5">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <p className="text-sm font-medium">{m.name}</p>
                      {m.completed && (
                        <UIBadge variant="default" className="text-xs">
                          Completed
                        </UIBadge>
                      )}
                    </div>
                    <span className="text-xs text-muted-foreground">
                      {m.current_value} / {m.target_value}
                    </span>
                  </div>
                  <p className="text-xs text-muted-foreground">{m.description}</p>
                  <Progress value={m.progress_pct} className="h-2" />
                  <div className="flex justify-between text-[10px] text-muted-foreground">
                    <span>{m.progress_pct}%</span>
                    {m.completed_at && <span>Completed {fmtDate(m.completed_at)}</span>}
                  </div>
                </div>
              ))
            ) : (
              <p className="text-sm text-muted-foreground text-center py-4">
                No milestones configured yet.
              </p>
            )}
          </CardContent>
        </Card>

        {/* Leaderboard */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Medal className="h-5 w-5 text-warning" /> Leaderboard
            </CardTitle>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-12">#</TableHead>
                  <TableHead>Agent</TableHead>
                  <TableHead className="text-center">Badges</TableHead>
                  <TableHead className="text-center">Milestones</TableHead>
                  <TableHead className="text-center">Streak</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {leaderboard.length > 0 ? (
                  leaderboard.map((item, i) => (
                    <TableRow
                      key={item.user_id}
                      className={item.user_id === agent.id ? 'bg-primary/5' : ''}
                    >
                      <TableCell className="font-medium">
                        {i < 3 ? (
                          <span
                            className={
                              i === 0
                                ? 'text-warning'
                                : i === 1
                                  ? 'text-muted-foreground'
                                  : 'text-orange-600'
                            }
                          >
                            {i + 1}
                          </span>
                        ) : (
                          i + 1
                        )}
                      </TableCell>
                      <TableCell className="font-medium">
                        {item.name}
                        {item.user_id === agent.id && (
                          <UIBadge variant="outline" className="ml-2 text-xs">
                            You
                          </UIBadge>
                        )}
                      </TableCell>
                      <TableCell className="text-center">{item.badge_count}</TableCell>
                      <TableCell className="text-center">{item.completed_milestones}</TableCell>
                      <TableCell className="text-center">{item.current_streak}d</TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={5} className="text-center text-muted-foreground">
                      No data yet.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      </div>
    </AgentLayout>
  );
}
