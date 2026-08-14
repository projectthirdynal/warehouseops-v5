import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Brain, RefreshCw, TrendingUp, AlertCircle } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { useToast } from '@/hooks/use-toast';

interface ModelAgent {
  agent_id: number;
  agent_name: string;
  conversion_rate: number;
  avg_handle_time_hrs: number;
  overall_score: number;
  total_cycles: number;
  total_sales: number;
  trained_at: string | null;
}

interface ModelData {
  model_version: string;
  agents_trained: number;
  last_trained_at: string | null;
  agents: ModelAgent[];
}

export default function PredictiveModelPanel() {
  const toast = useToast();
  const [model, setModel] = useState<ModelData | null>(null);
  const [loading, setLoading] = useState(true);
  const [retraining, setRetraining] = useState(false);

  const fetchModel = () => {
    setLoading(true);
    fetch('/distribution/predictive/model', { headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => setModel(d))
      .catch(() => {})
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    fetchModel();
  }, []);

  const handleRetrain = () => {
    setRetraining(true);
    router.post(
      '/distribution/predictive/retrain',
      {},
      {
        onSuccess: () => {
          toast.success('Predictive model retrained');
          fetchModel();
        },
        onError: () => toast.error('Retraining failed'),
        onFinish: () => setRetraining(false),
      }
    );
  };

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between pb-2">
        <CardTitle className="text-sm font-medium flex items-center gap-2">
          <Brain className="h-4 w-4 text-purple-600" />
          Predictive Assignment Model
        </CardTitle>
        <Button
          size="sm"
          variant="outline"
          onClick={handleRetrain}
          disabled={retraining || loading}
        >
          <RefreshCw className={`mr-1.5 h-3.5 w-3.5 ${retraining ? 'animate-spin' : ''}`} />
          {retraining ? 'Retraining...' : 'Retrain Model'}
        </Button>
      </CardHeader>
      <CardContent>
        {loading ? (
          <div className="flex items-center justify-center py-8 text-muted-foreground">
            <RefreshCw className="h-4 w-4 animate-spin mr-2" />
            Loading model data...
          </div>
        ) : !model || model.agents_trained === 0 ? (
          <div className="flex flex-col items-center justify-center py-8 text-muted-foreground">
            <AlertCircle className="h-8 w-8 mb-2 opacity-40" />
            <p className="text-sm">Model not trained yet.</p>
            <p className="text-xs mt-1">
              Click "Retrain Model" to build predictions from historical lead cycle data.
            </p>
          </div>
        ) : (
          <div className="space-y-4">
            {/* Model stats */}
            <div className="flex items-center gap-4 text-xs text-muted-foreground">
              <span>
                Version: <Badge variant="outline">{model.model_version}</Badge>
              </span>
              <span>
                Agents trained: <strong>{model.agents_trained}</strong>
              </span>
              <span>
                Last trained:{' '}
                {model.last_trained_at ? new Date(model.last_trained_at).toLocaleString() : '—'}
              </span>
            </div>

            {/* Agent ranking */}
            <div className="space-y-2">
              {model.agents.map((agent, idx) => (
                <div key={agent.agent_id} className="flex items-center gap-3 rounded-lg border p-3">
                  <div className="flex items-center gap-2 w-32 shrink-0">
                    <Badge variant="outline" className="text-xs">
                      #{idx + 1}
                    </Badge>
                    <span className="text-sm font-medium truncate">{agent.agent_name}</span>
                  </div>
                  <div className="flex-1">
                    <div className="flex items-center gap-2 mb-1">
                      <Progress value={agent.overall_score} className="h-2" />
                      <span className="text-xs font-medium tabular-nums w-12 text-right">
                        {agent.overall_score}%
                      </span>
                    </div>
                    <div className="flex items-center gap-4 text-xs text-muted-foreground">
                      <span className="flex items-center gap-1">
                        <TrendingUp className="h-3 w-3" />
                        {agent.conversion_rate}% conv
                      </span>
                      <span>
                        {agent.total_sales}/{agent.total_cycles} sold
                      </span>
                      <span>
                        {agent.avg_handle_time_hrs > 0
                          ? `${agent.avg_handle_time_hrs}h avg`
                          : 'N/A'}
                      </span>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
