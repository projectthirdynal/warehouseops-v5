import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
  ArrowLeft,
  Brain,
  RefreshCw,
  TrendingUp,
  Target,
  Zap,
  ChevronDown,
  ChevronRight,
} from 'lucide-react';

interface FeatureContributions {
  [key: string]: number;
}

interface Features {
  [key: string]: number;
}

interface ScoredPair {
  customer_a_id: number;
  customer_a_name: string;
  customer_a_phone: string;
  customer_a_orders: number;
  customer_b_id: number;
  customer_b_name: string;
  customer_b_phone: string;
  customer_b_orders: number;
  score: number;
  features: Features;
  feature_contributions: FeatureContributions;
}

interface ActiveModel {
  id: number;
  version: string;
  training_samples: number;
  accuracy: number | null;
  precision: number | null;
  recall: number | null;
  f1_score: number | null;
  trained_at: string | null;
  trained_at_formatted: string | null;
}

interface ModelHistoryItem {
  id: number;
  version: string;
  training_samples: number;
  accuracy: number | null;
  f1_score: number | null;
  trained_at: string | null;
  is_active: boolean;
}

interface ModelStats {
  active_model: ActiveModel | null;
  feature_weights: Record<string, number>;
  feature_importance: Record<string, number>;
  model_history: ModelHistoryItem[];
  default_weights: Record<string, number>;
}

interface Props {
  pairs: ScoredPair[];
  totalPairs: number;
  modelVersion: string;
  modelStats: ModelStats;
  minScore: number;
}

const scoreColor = (score: number): string => {
  if (score >= 90) return 'bg-red-100 text-red-700';
  if (score >= 80) return 'bg-orange-100 text-orange-700';
  if (score >= 70) return 'bg-yellow-100 text-yellow-700';
  return 'bg-muted text-muted-foreground';
};

const scoreLabel = (score: number): string => {
  if (score >= 90) return 'Very High';
  if (score >= 80) return 'High';
  if (score >= 70) return 'Medium';
  return 'Low';
};

const formatFeatureName = (key: string): string => {
  return key
    .split('_')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
};

export default function DuplicateReviewMLScoring({
  pairs,
  totalPairs,
  modelVersion,
  modelStats,
  minScore,
}: Props) {
  const [scanning, setScanning] = useState(false);
  const [training, setTraining] = useState(false);
  const [trainResult, setTrainResult] = useState<string | null>(null);
  const [expandedPairs, setExpandedPairs] = useState<Set<number>>(new Set());
  const [minScoreFilter, setMinScoreFilter] = useState(minScore.toString());

  const activeModel = modelStats.active_model;
  const featureImportance = modelStats.feature_importance ?? {};
  const modelHistory = modelStats.model_history ?? [];

  const runScan = () => {
    setScanning(true);
    router.post(
      '/api/duplicate-check/ml/scan',
      { min_score: parseFloat(minScoreFilter) || 70, limit: 100 },
      {
        preserveScroll: true,
        onSuccess: () => {
          router.reload({ only: ['pairs', 'totalPairs', 'modelVersion'] });
          setScanning(false);
        },
        onError: () => setScanning(false),
      }
    );
  };

  const trainModel = () => {
    setTraining(true);
    setTrainResult(null);
    router.post(
      '/api/duplicate-check/ml/train',
      { epochs: 100, learning_rate: 0.01 },
      {
        preserveScroll: true,
        onSuccess: (page) => {
          setTraining(false);
          const props = page.props as Record<string, unknown>;
          const result = props as Record<string, unknown>;
          if (result.success) {
            setTrainResult(
              `Model trained: v${result.version}, ${((result.accuracy as number) * 100).toFixed(1)}% accuracy, ${result.training_samples} samples`
            );
          } else {
            setTrainResult((result.message as string) ?? 'Training completed');
          }
          router.reload({ only: ['modelStats'] });
        },
        onError: () => {
          setTraining(false);
          setTrainResult('Training failed. Check logs.');
        },
      }
    );
  };

  const togglePair = (idx: number) => {
    setExpandedPairs((prev) => {
      const next = new Set(prev);
      if (next.has(idx)) {
        next.delete(idx);
      } else {
        next.add(idx);
      }
      return next;
    });
  };

  return (
    <AppLayout>
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="flex items-center gap-2 text-2xl font-bold">
              <Brain className="h-7 w-7 text-info" />
              ML-Based Duplicate Scoring
            </h1>
            <p className="mt-1 text-sm text-muted-foreground">
              Logistic regression model scoring customer pairs using 13 features. Model:{' '}
              {modelVersion}
            </p>
          </div>
          <div className="flex gap-2">
            <Link href="/shop/duplicate-review">
              <Button variant="outline" size="sm">
                <ArrowLeft className="mr-1.5 h-4 w-4" />
                Back to Queue
              </Button>
            </Link>
          </div>
        </div>

        {/* Model Stats Cards */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Model Version</p>
                <Brain className="h-4 w-4 text-info" />
              </div>
              <p className="text-lg font-bold">{activeModel?.version ?? 'default'}</p>
              <p className="text-xs text-muted-foreground">
                {activeModel?.trained_at_formatted ?? 'Untrained'}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Training Samples</p>
                <Target className="h-4 w-4 text-blue-500" />
              </div>
              <p className="text-lg font-bold">{activeModel?.training_samples ?? 0}</p>
              <p className="text-xs text-muted-foreground">labeled decisions</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Accuracy</p>
                <TrendingUp className="h-4 w-4 text-green-500" />
              </div>
              <p className="text-lg font-bold">
                {activeModel?.accuracy != null
                  ? `${(activeModel.accuracy * 100).toFixed(1)}%`
                  : '—'}
              </p>
              <p className="text-xs text-muted-foreground">on training set</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">F1 Score</p>
                <Zap className="h-4 w-4 text-orange-500" />
              </div>
              <p className="text-lg font-bold">
                {activeModel?.f1_score != null ? activeModel.f1_score.toFixed(3) : '—'}
              </p>
              <p className="text-xs text-muted-foreground">precision/recall balance</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Pairs Found</p>
                <Brain className="h-4 w-4 text-purple-500" />
              </div>
              <p className="text-lg font-bold">{totalPairs}</p>
              <p className="text-xs text-muted-foreground">scored &ge; {minScoreFilter}%</p>
            </CardContent>
          </Card>
        </div>

        {/* Training & Actions */}
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex items-center gap-2">
            <span className="text-sm text-muted-foreground">Min Score:</span>
            <select
              value={minScoreFilter}
              onChange={(e) => setMinScoreFilter(e.target.value)}
              className="rounded-md border bg-background px-3 py-1 text-sm"
            >
              <option value="50">50%</option>
              <option value="60">60%</option>
              <option value="70">70%</option>
              <option value="80">80%</option>
              <option value="90">90%</option>
            </select>
          </div>
          <Button onClick={runScan} size="sm" disabled={scanning}>
            <RefreshCw className={`mr-1.5 h-4 w-4 ${scanning ? 'animate-spin' : ''}`} />
            {scanning ? 'Scanning...' : 'Run ML Scan'}
          </Button>
          <Button onClick={trainModel} size="sm" variant="outline" disabled={training}>
            <Brain className={`mr-1.5 h-4 w-4 ${training ? 'animate-pulse' : ''}`} />
            {training ? 'Training...' : 'Train Model'}
          </Button>
          {trainResult && <span className="text-sm text-muted-foreground">{trainResult}</span>}
        </div>

        {/* Feature Importance */}
        {Object.keys(featureImportance).length > 0 && (
          <Card>
            <CardContent className="p-4">
              <p className="mb-3 text-sm font-medium">
                Feature Importance (Weight &times; Magnitude)
              </p>
              <div className="space-y-1.5">
                {Object.entries(featureImportance)
                  .sort(([, a], [, b]) => Math.abs(b) - Math.abs(a))
                  .slice(0, 13)
                  .map(([key, weight]) => {
                    const maxWeight = Math.max(
                      ...Object.values(featureImportance).map((w) => Math.abs(w)),
                      0.01
                    );
                    const barWidth = (Math.abs(weight) / maxWeight) * 100;
                    return (
                      <div key={key} className="flex items-center gap-2 text-xs">
                        <span className="w-40 shrink-0 text-muted-foreground">
                          {formatFeatureName(key)}
                        </span>
                        <div className="relative h-4 flex-1 rounded bg-muted/50">
                          <div
                            className={`absolute h-full rounded ${
                              weight >= 0 ? 'bg-green-400' : 'bg-red-400'
                            }`}
                            style={{ width: `${barWidth}%` }}
                          />
                        </div>
                        <span className="w-12 shrink-0 text-right font-mono">
                          {weight.toFixed(3)}
                        </span>
                      </div>
                    );
                  })}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Model History */}
        {modelHistory.length > 0 && (
          <Card>
            <CardContent className="p-4">
              <p className="mb-3 text-sm font-medium">Model History</p>
              <div className="space-y-2">
                {modelHistory.map((m) => (
                  <div key={m.id} className="flex items-center justify-between text-sm">
                    <span className="flex items-center gap-2">
                      <span className="font-medium">{m.version}</span>
                      {m.is_active && (
                        <Badge className="bg-green-100 text-green-700 text-xs">Active</Badge>
                      )}
                    </span>
                    <div className="flex gap-3 text-xs text-muted-foreground">
                      <span>{m.training_samples} samples</span>
                      <span>{m.accuracy != null ? `${(m.accuracy * 100).toFixed(1)}%` : '—'}</span>
                      <span>F1: {m.f1_score != null ? m.f1_score.toFixed(3) : '—'}</span>
                      <span>
                        {m.trained_at ? new Date(m.trained_at).toLocaleDateString() : '—'}
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Scored Pairs */}
        {pairs.length > 0 ? (
          <div className="space-y-3">
            <p className="text-sm font-medium">Top {pairs.length} Scored Pairs (sorted by score)</p>
            {pairs.map((pair, idx) => {
              const isExpanded = expandedPairs.has(idx);
              return (
                <Card key={idx}>
                  <CardContent className="p-4">
                    <button
                      onClick={() => togglePair(idx)}
                      className="flex w-full items-center gap-3 text-left"
                    >
                      {isExpanded ? (
                        <ChevronDown className="h-4 w-4 shrink-0 text-muted-foreground" />
                      ) : (
                        <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                      )}
                      <Badge className={`shrink-0 ${scoreColor(pair.score)}`}>
                        {pair.score.toFixed(1)}% · {scoreLabel(pair.score)}
                      </Badge>
                      <div className="min-w-0 flex-1">
                        <span className="text-sm font-medium">{pair.customer_a_name}</span>
                        <span className="mx-1.5 text-muted-foreground">vs</span>
                        <span className="text-sm font-medium">{pair.customer_b_name}</span>
                      </div>
                      <div className="hidden gap-3 text-xs text-muted-foreground sm:flex">
                        <span>
                          {pair.customer_a_orders} + {pair.customer_b_orders} orders
                        </span>
                        {pair.customer_a_phone && <span>{pair.customer_a_phone}</span>}
                      </div>
                    </button>

                    {isExpanded && (
                      <div className="mt-4 space-y-3 border-t pt-3">
                        {/* Customer Details */}
                        <div className="grid gap-3 sm:grid-cols-2">
                          <div className="rounded-md border bg-muted/30 p-3">
                            <p className="text-xs font-medium text-muted-foreground">Customer A</p>
                            <p className="text-sm font-medium">{pair.customer_a_name}</p>
                            <p className="text-xs text-muted-foreground">
                              ID: {pair.customer_a_id} · Phone: {pair.customer_a_phone || '—'} ·{' '}
                              {pair.customer_a_orders} orders
                            </p>
                          </div>
                          <div className="rounded-md border bg-muted/30 p-3">
                            <p className="text-xs font-medium text-muted-foreground">Customer B</p>
                            <p className="text-sm font-medium">{pair.customer_b_name}</p>
                            <p className="text-xs text-muted-foreground">
                              ID: {pair.customer_b_id} · Phone: {pair.customer_b_phone || '—'} ·{' '}
                              {pair.customer_b_orders} orders
                            </p>
                          </div>
                        </div>

                        {/* Feature Contributions */}
                        <div>
                          <p className="mb-2 text-xs font-medium text-muted-foreground">
                            Feature Contributions (sorted by impact)
                          </p>
                          <div className="space-y-1">
                            {Object.entries(pair.feature_contributions)
                              .filter(([, v]) => Math.abs(v) > 0.001)
                              .map(([key, contribution]) => {
                                const maxValue = Math.max(
                                  ...Object.values(pair.feature_contributions).map((v) =>
                                    Math.abs(v)
                                  ),
                                  0.01
                                );
                                const barWidth = (Math.abs(contribution) / maxValue) * 100;
                                return (
                                  <div key={key} className="flex items-center gap-2 text-xs">
                                    <span className="w-40 shrink-0 text-muted-foreground">
                                      {formatFeatureName(key)}
                                    </span>
                                    <div className="relative h-3 flex-1 rounded bg-muted/50">
                                      <div
                                        className={`absolute h-full rounded ${
                                          contribution >= 0 ? 'bg-green-400' : 'bg-red-400'
                                        }`}
                                        style={{ width: `${barWidth}%` }}
                                      />
                                    </div>
                                    <span className="w-16 shrink-0 text-right font-mono">
                                      {contribution >= 0 ? '+' : ''}
                                      {contribution.toFixed(4)}
                                    </span>
                                  </div>
                                );
                              })}
                          </div>
                        </div>

                        {/* Raw Features */}
                        <details>
                          <summary className="cursor-pointer text-xs text-muted-foreground">
                            Raw Feature Values
                          </summary>
                          <div className="mt-2 grid gap-1 sm:grid-cols-3">
                            {Object.entries(pair.features).map(([key, value]) => (
                              <span key={key} className="rounded bg-muted/30 px-2 py-0.5 text-xs">
                                {formatFeatureName(key)}: {value.toFixed(3)}
                              </span>
                            ))}
                          </div>
                        </details>
                      </div>
                    )}
                  </CardContent>
                </Card>
              );
            })}
          </div>
        ) : (
          <Card>
            <CardContent className="flex flex-col items-center justify-center py-12">
              <Brain className="mb-3 h-12 w-12 text-muted-foreground" />
              <p className="text-sm text-muted-foreground">
                No pairs scored above {minScoreFilter}%. Run an ML scan to detect duplicates using
                the trained model.
              </p>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
