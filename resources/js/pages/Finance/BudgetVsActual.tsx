import { useState, useCallback } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  DialogDescription,
} from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  Plus,
  AlertTriangle,
  CheckCircle,
  TrendingDown,
  TrendingUp,
  Wallet,
  Target,
  DollarSign,
} from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';

interface BudgetLineComparison {
  id: number;
  category: string;
  line_type: 'INCOME' | 'EXPENSE';
  budgeted_amount: number;
  actual_amount: number;
  variance_amount: number;
  variance_percent: number;
  threshold_percent: number;
  is_over_threshold: boolean;
  notes: string | null;
}

interface BudgetSummary {
  total_budgeted_income: number;
  total_actual_income: number;
  total_budgeted_expense: number;
  total_actual_expense: number;
  net_budgeted: number;
  net_actual: number;
  net_variance: number;
}

interface ActiveComparison {
  budget: {
    id: number;
    department: string;
    name: string;
    period_type: string;
    period_start: string;
    period_end: string;
    status: string;
  };
  lines: BudgetLineComparison[];
  summary: BudgetSummary;
}

interface BudgetListItem {
  id: number;
  department: string;
  name: string;
  period_type: string;
  period_start: string;
  period_end: string;
  status: string;
  line_count: number;
  total_budgeted: number;
}

interface VarianceAlert {
  id: number;
  budget_id: number;
  budget_line_id: number;
  budgeted_amount: number;
  actual_amount: number;
  variance_amount: number;
  variance_percent: number;
  severity: 'WARNING' | 'CRITICAL';
  message: string;
  is_resolved: boolean;
  resolved_at: string | null;
  created_at: string;
  budget?: { id: number; department: string; name: string };
  budgetLine?: { id: number; category: string; line_type: string };
  resolver?: { name: string };
}

interface Props {
  budgets?: BudgetListItem[];
  activeComparisons?: ActiveComparison[];
  unresolvedAlerts?: VarianceAlert[];
  alertSummary?: { total: number; critical: number; warning: number };
  budget?: any;
  comparison?: { budget: any; lines: BudgetLineComparison[]; summary: BudgetSummary };
  alerts?: VarianceAlert[];
}

const CATEGORIES = [
  { value: 'REVENUE', label: 'Revenue', type: 'INCOME' },
  { value: 'REFUNDS', label: 'Refunds', type: 'EXPENSE' },
  { value: 'COGS', label: 'COGS', type: 'EXPENSE' },
  { value: 'SHIPPING', label: 'Shipping', type: 'EXPENSE' },
  { value: 'COMMISSIONS', label: 'Commissions', type: 'EXPENSE' },
  { value: 'OTHER_EXPENSE', label: 'Other Expense', type: 'EXPENSE' },
];

export default function BudgetVsActual({
  budgets = [],
  activeComparisons = [],
  unresolvedAlerts = [],
  alertSummary = { total: 0, critical: 0, warning: 0 },
  budget: selectedBudget,
  comparison,
  alerts = [],
}: Props) {
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [selectedBudgetId, setSelectedBudgetId] = useState<number | null>(
    selectedBudget?.id ?? null
  );
  const [currentComparison, setCurrentComparison] = useState<ActiveComparison | null>(
    comparison
      ? { budget: comparison.budget, lines: comparison.lines, summary: comparison.summary }
      : null
  );
  const [currentAlerts, setCurrentAlerts] = useState<VarianceAlert[]>(alerts);
  const [loading, setLoading] = useState(false);

  // Form state
  const [form, setForm] = useState({
    department: '',
    name: '',
    period_type: 'MONTHLY',
    period_start: '',
    period_end: '',
    status: 'DRAFT',
    notes: '',
  });
  const [lines, setLines] = useState<
    Array<{
      category: string;
      line_type: string;
      budgeted_amount: string;
      threshold_percent: string;
    }>
  >([{ category: 'REVENUE', line_type: 'INCOME', budgeted_amount: '', threshold_percent: '10' }]);

  const fetchComparison = useCallback(async (id: number) => {
    setLoading(true);
    try {
      const [compRes, alertRes] = await Promise.all([
        axios.get(`/finance/budget/api/${id}/comparison`),
        axios.get(`/finance/budget/api/${id}/alerts`),
      ]);
      setCurrentComparison(compRes.data);
      setCurrentAlerts(alertRes.data);
      setSelectedBudgetId(id);
    } catch {
      toast.error('Failed to load budget comparison');
    } finally {
      setLoading(false);
    }
  }, []);

  const handleCreateBudget = async () => {
    try {
      const payload = {
        ...form,
        lines: lines.map((l) => ({
          category: l.category,
          line_type: l.line_type,
          budgeted_amount: parseFloat(l.budgeted_amount) || 0,
          threshold_percent: parseFloat(l.threshold_percent) || 10,
        })),
      };
      const res = await axios.post('/finance/budget/api', payload);
      toast.success('Budget created successfully');
      setShowCreateModal(false);
      router.visit('/finance/budget');
      if (res.data.id) {
        fetchComparison(res.data.id);
      }
    } catch {
      toast.error('Failed to create budget');
    }
  };

  const handleGenerateAlerts = async () => {
    if (!selectedBudgetId) return;
    try {
      const res = await axios.post(`/finance/budget/api/${selectedBudgetId}/generate-alerts`);
      toast.success(`Generated ${res.data.generated} variance alert(s)`);
      fetchComparison(selectedBudgetId);
    } catch {
      toast.error('Failed to generate alerts');
    }
  };

  const handleResolveAlert = async (alertId: number) => {
    try {
      await axios.patch(`/finance/budget/api/alerts/${alertId}/resolve`);
      toast.success('Alert resolved');
      if (selectedBudgetId) {
        fetchComparison(selectedBudgetId);
      }
    } catch {
      toast.error('Failed to resolve alert');
    }
  };

  const addLine = () => {
    setLines([
      ...lines,
      { category: 'COGS', line_type: 'EXPENSE', budgeted_amount: '', threshold_percent: '10' },
    ]);
  };

  const removeLine = (idx: number) => {
    setLines(lines.filter((_, i) => i !== idx));
  };

  const updateLine = (idx: number, field: string, value: string) => {
    const updated = [...lines];
    updated[idx] = { ...updated[idx], [field]: value };
    if (field === 'category') {
      const cat = CATEGORIES.find((c) => c.value === value);
      if (cat) updated[idx].line_type = cat.type;
    }
    setLines(updated);
  };

  const getVarianceColor = (percent: number, lineType: string) => {
    const isPositive = percent > 0;
    if (lineType === 'INCOME') {
      return isPositive ? 'text-green-600' : 'text-red-600';
    }
    return isPositive ? 'text-red-600' : 'text-green-600';
  };

  const getVarianceIcon = (percent: number, lineType: string) => {
    const isPositive = percent > 0;
    const isFavorable = lineType === 'INCOME' ? isPositive : !isPositive;
    return isFavorable ? (
      <TrendingUp className="h-4 w-4 text-green-600" />
    ) : (
      <TrendingDown className="h-4 w-4 text-red-600" />
    );
  };

  return (
    <AppLayout>
      <Head title="Budget vs Actual" />
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Budget vs Actual</h1>
            <p className="text-sm text-muted-foreground">
              Department budgets with variance tracking and alerts
            </p>
          </div>
          <Button onClick={() => setShowCreateModal(true)}>
            <Plus className="mr-2 h-4 w-4" />
            New Budget
          </Button>
        </div>

        {/* Alert Summary Cards */}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
          <Card>
            <CardContent className="flex items-center gap-3 p-4">
              <Wallet className="h-8 w-8 text-blue-600" />
              <div>
                <p className="text-sm text-muted-foreground">Total Budgets</p>
                <p className="text-xl font-bold">{budgets.length}</p>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="flex items-center gap-3 p-4">
              <AlertTriangle className="h-8 w-8 text-amber-600" />
              <div>
                <p className="text-sm text-muted-foreground">Unresolved Alerts</p>
                <p className="text-xl font-bold">{alertSummary.total}</p>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="flex items-center gap-3 p-4">
              <AlertTriangle className="h-8 w-8 text-red-600" />
              <div>
                <p className="text-sm text-muted-foreground">Critical</p>
                <p className="text-xl font-bold text-red-600">{alertSummary.critical}</p>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="flex items-center gap-3 p-4">
              <Target className="h-8 w-8 text-green-600" />
              <div>
                <p className="text-sm text-muted-foreground">Warnings</p>
                <p className="text-xl font-bold text-amber-600">{alertSummary.warning}</p>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Budgets List */}
        <Card>
          <CardHeader>
            <CardTitle>Budgets</CardTitle>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Department</TableHead>
                  <TableHead>Name</TableHead>
                  <TableHead>Period</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Lines</TableHead>
                  <TableHead>Total Budgeted</TableHead>
                  <TableHead>Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {budgets.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={8} className="text-center text-muted-foreground">
                      No budgets yet. Click "New Budget" to create one.
                    </TableCell>
                  </TableRow>
                ) : (
                  budgets.map((b) => (
                    <TableRow key={b.id}>
                      <TableCell className="font-medium">{b.department}</TableCell>
                      <TableCell>{b.name}</TableCell>
                      <TableCell>
                        {formatDate(b.period_start)} — {formatDate(b.period_end)}
                      </TableCell>
                      <TableCell>{b.period_type}</TableCell>
                      <TableCell>
                        <Badge
                          variant={
                            b.status === 'ACTIVE'
                              ? 'default'
                              : b.status === 'CLOSED'
                                ? 'secondary'
                                : 'outline'
                          }
                        >
                          {b.status}
                        </Badge>
                      </TableCell>
                      <TableCell>{b.line_count}</TableCell>
                      <TableCell>{formatCurrency(b.total_budgeted)}</TableCell>
                      <TableCell>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => fetchComparison(b.id)}
                          disabled={loading}
                        >
                          {loading && selectedBudgetId === b.id ? 'Loading...' : 'Compare'}
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        {/* Budget Comparison */}
        {currentComparison && (
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>
                Budget Comparison: {currentComparison.budget.department} —{' '}
                {currentComparison.budget.name}
              </CardTitle>
              <Button variant="outline" size="sm" onClick={handleGenerateAlerts}>
                <AlertTriangle className="mr-2 h-4 w-4" />
                Generate Alerts
              </Button>
            </CardHeader>
            <CardContent>
              {/* Summary Cards */}
              <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div className="rounded-lg border p-3">
                  <p className="text-xs text-muted-foreground">Budgeted Income</p>
                  <p className="text-lg font-bold text-green-600">
                    {formatCurrency(currentComparison.summary.total_budgeted_income)}
                  </p>
                </div>
                <div className="rounded-lg border p-3">
                  <p className="text-xs text-muted-foreground">Actual Income</p>
                  <p className="text-lg font-bold text-green-600">
                    {formatCurrency(currentComparison.summary.total_actual_income)}
                  </p>
                </div>
                <div className="rounded-lg border p-3">
                  <p className="text-xs text-muted-foreground">Budgeted Expense</p>
                  <p className="text-lg font-bold text-red-600">
                    {formatCurrency(currentComparison.summary.total_budgeted_expense)}
                  </p>
                </div>
                <div className="rounded-lg border p-3">
                  <p className="text-xs text-muted-foreground">Actual Expense</p>
                  <p className="text-lg font-bold text-red-600">
                    {formatCurrency(currentComparison.summary.total_actual_expense)}
                  </p>
                </div>
              </div>

              {/* Net Summary */}
              <div className="mb-6 flex items-center justify-between rounded-lg bg-muted p-4">
                <div className="flex items-center gap-2">
                  <DollarSign className="h-5 w-5" />
                  <span className="font-medium">Net Variance:</span>
                </div>
                <span
                  className={`text-lg font-bold ${
                    currentComparison.summary.net_variance >= 0 ? 'text-green-600' : 'text-red-600'
                  }`}
                >
                  {formatCurrency(currentComparison.summary.net_variance)}
                </span>
              </div>

              {/* Line Items Table */}
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Category</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Budgeted</TableHead>
                    <TableHead>Actual</TableHead>
                    <TableHead>Variance</TableHead>
                    <TableHead>Variance %</TableHead>
                    <TableHead>Status</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {currentComparison.lines.map((line) => (
                    <TableRow key={line.id}>
                      <TableCell className="font-medium">{line.category}</TableCell>
                      <TableCell>
                        <Badge variant={line.line_type === 'INCOME' ? 'default' : 'secondary'}>
                          {line.line_type}
                        </Badge>
                      </TableCell>
                      <TableCell>{formatCurrency(line.budgeted_amount)}</TableCell>
                      <TableCell>{formatCurrency(line.actual_amount)}</TableCell>
                      <TableCell
                        className={getVarianceColor(line.variance_percent, line.line_type)}
                      >
                        {formatCurrency(line.variance_amount)}
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-1">
                          {getVarianceIcon(line.variance_percent, line.line_type)}
                          <span className={getVarianceColor(line.variance_percent, line.line_type)}>
                            {line.variance_percent}%
                          </span>
                        </div>
                      </TableCell>
                      <TableCell>
                        {line.is_over_threshold ? (
                          <Badge variant="destructive">Over Threshold</Badge>
                        ) : (
                          <Badge variant="outline">
                            <CheckCircle className="mr-1 h-3 w-3" />
                            OK
                          </Badge>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}

        {/* Active Budget Comparisons (dashboard view) */}
        {!currentComparison && activeComparisons.length > 0 && (
          <div className="space-y-4">
            {activeComparisons.map((comp) => (
              <Card key={comp.budget.id}>
                <CardHeader>
                  <CardTitle>
                    {comp.budget.department} — {comp.budget.name}
                  </CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="mb-4 grid grid-cols-3 gap-4">
                    <div>
                      <p className="text-xs text-muted-foreground">Net Budgeted</p>
                      <p className="font-bold">{formatCurrency(comp.summary.net_budgeted)}</p>
                    </div>
                    <div>
                      <p className="text-xs text-muted-foreground">Net Actual</p>
                      <p className="font-bold">{formatCurrency(comp.summary.net_actual)}</p>
                    </div>
                    <div>
                      <p className="text-xs text-muted-foreground">Net Variance</p>
                      <p
                        className={`font-bold ${
                          comp.summary.net_variance >= 0 ? 'text-green-600' : 'text-red-600'
                        }`}
                      >
                        {formatCurrency(comp.summary.net_variance)}
                      </p>
                    </div>
                  </div>
                  <Button variant="ghost" size="sm" onClick={() => fetchComparison(comp.budget.id)}>
                    View Details
                  </Button>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

        {/* Variance Alerts */}
        {currentAlerts.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Variance Alerts</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-3">
                {currentAlerts.map((alert) => (
                  <div
                    key={alert.id}
                    className={`flex items-center justify-between rounded-lg border p-3 ${
                      alert.severity === 'CRITICAL'
                        ? 'border-red-300 bg-red-50'
                        : 'border-amber-300 bg-amber-50'
                    }`}
                  >
                    <div className="flex items-center gap-3">
                      <AlertTriangle
                        className={`h-5 w-5 ${
                          alert.severity === 'CRITICAL' ? 'text-red-600' : 'text-amber-600'
                        }`}
                      />
                      <div>
                        <p className="font-medium">{alert.message}</p>
                        <p className="text-xs text-muted-foreground">
                          Variance: {formatCurrency(alert.variance_amount)} (
                          {alert.variance_percent}%) • {formatDate(alert.created_at)}
                        </p>
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      <Badge variant={alert.severity === 'CRITICAL' ? 'destructive' : 'default'}>
                        {alert.severity}
                      </Badge>
                      {alert.is_resolved ? (
                        <Badge variant="outline">
                          <CheckCircle className="mr-1 h-3 w-3" />
                          Resolved
                        </Badge>
                      ) : (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => handleResolveAlert(alert.id)}
                        >
                          Resolve
                        </Button>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Unresolved Alerts (dashboard view) */}
        {!currentComparison && unresolvedAlerts.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Unresolved Alerts</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-3">
                {unresolvedAlerts.map((alert) => (
                  <div
                    key={alert.id}
                    className={`flex items-center justify-between rounded-lg border p-3 ${
                      alert.severity === 'CRITICAL'
                        ? 'border-red-300 bg-red-50'
                        : 'border-amber-300 bg-amber-50'
                    }`}
                  >
                    <div className="flex items-center gap-3">
                      <AlertTriangle
                        className={`h-5 w-5 ${
                          alert.severity === 'CRITICAL' ? 'text-red-600' : 'text-amber-600'
                        }`}
                      />
                      <div>
                        <p className="font-medium">{alert.message}</p>
                        <p className="text-xs text-muted-foreground">
                          {alert.budget?.department} — {alert.budget?.name} •{' '}
                          {formatDate(alert.created_at)}
                        </p>
                      </div>
                    </div>
                    <Badge variant={alert.severity === 'CRITICAL' ? 'destructive' : 'default'}>
                      {alert.severity}
                    </Badge>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        )}
      </div>

      {/* Create Budget Modal */}
      <Dialog open={showCreateModal} onOpenChange={setShowCreateModal}>
        <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Create New Budget</DialogTitle>
            <DialogDescription>
              Define a department budget with categories and threshold percentages.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label htmlFor="department">Department</Label>
                <Input
                  id="department"
                  value={form.department}
                  onChange={(e) => setForm({ ...form, department: e.target.value })}
                  placeholder="e.g. Operations"
                />
              </div>
              <div>
                <Label htmlFor="name">Budget Name</Label>
                <Input
                  id="name"
                  value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                  placeholder="e.g. Q1 2026 Operations Budget"
                />
              </div>
            </div>

            <div className="grid grid-cols-3 gap-4">
              <div>
                <Label>Period Type</Label>
                <Select
                  value={form.period_type}
                  onValueChange={(v) => setForm({ ...form, period_type: v })}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="MONTHLY">Monthly</SelectItem>
                    <SelectItem value="QUARTERLY">Quarterly</SelectItem>
                    <SelectItem value="YEARLY">Yearly</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label htmlFor="period_start">Period Start</Label>
                <Input
                  id="period_start"
                  type="date"
                  value={form.period_start}
                  onChange={(e) => setForm({ ...form, period_start: e.target.value })}
                />
              </div>
              <div>
                <Label htmlFor="period_end">Period End</Label>
                <Input
                  id="period_end"
                  type="date"
                  value={form.period_end}
                  onChange={(e) => setForm({ ...form, period_end: e.target.value })}
                />
              </div>
            </div>

            <div>
              <Label>Status</Label>
              <Select value={form.status} onValueChange={(v) => setForm({ ...form, status: v })}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="DRAFT">Draft</SelectItem>
                  <SelectItem value="ACTIVE">Active</SelectItem>
                  <SelectItem value="CLOSED">Closed</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {/* Budget Lines */}
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label>Budget Lines</Label>
                <Button variant="outline" size="sm" onClick={addLine}>
                  <Plus className="mr-1 h-3 w-3" />
                  Add Line
                </Button>
              </div>
              {lines.map((line, idx) => (
                <div key={idx} className="flex items-end gap-2">
                  <div className="flex-1">
                    <Label className="text-xs">Category</Label>
                    <Select
                      value={line.category}
                      onValueChange={(v) => updateLine(idx, 'category', v)}
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {CATEGORIES.map((c) => (
                          <SelectItem key={c.value} value={c.value}>
                            {c.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="w-28">
                    <Label className="text-xs">Type</Label>
                    <Input value={line.line_type} disabled className="bg-muted" />
                  </div>
                  <div className="w-32">
                    <Label className="text-xs">Amount</Label>
                    <Input
                      type="number"
                      value={line.budgeted_amount}
                      onChange={(e) => updateLine(idx, 'budgeted_amount', e.target.value)}
                      placeholder="0.00"
                    />
                  </div>
                  <div className="w-24">
                    <Label className="text-xs">Threshold %</Label>
                    <Input
                      type="number"
                      value={line.threshold_percent}
                      onChange={(e) => updateLine(idx, 'threshold_percent', e.target.value)}
                      placeholder="10"
                    />
                  </div>
                  {lines.length > 1 && (
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => removeLine(idx)}
                      className="text-red-600"
                    >
                      Remove
                    </Button>
                  )}
                </div>
              ))}
            </div>

            <div>
              <Label htmlFor="notes">Notes (optional)</Label>
              <Input
                id="notes"
                value={form.notes}
                onChange={(e) => setForm({ ...form, notes: e.target.value })}
                placeholder="Additional notes..."
              />
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setShowCreateModal(false)}>
              Cancel
            </Button>
            <Button onClick={handleCreateBudget}>Create Budget</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
