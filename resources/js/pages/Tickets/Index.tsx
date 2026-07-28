import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
  Headphones,
  Plus,
  Search,
  Filter,
  MoreHorizontal,
  Eye,
  MessageSquare,
  Clock,
  CheckCircle,
  AlertCircle,
  User,
  Settings,
  BarChart3,
  Download,
  Timer,
  AlertTriangle,
  XCircle,
  Tag,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { formatDate } from '@/lib/utils';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

interface Ticket {
  id: number;
  ticket_number: string;
  subject: string;
  description: string;
  status: 'open' | 'in_progress' | 'waiting' | 'resolved' | 'closed';
  priority: 'low' | 'medium' | 'high' | 'urgent';
  category: string;
  created_by: {
    id: number;
    name: string;
  };
  assigned_to?: {
    id: number;
    name: string;
  };
  related_waybill?: string;
  related_lead?: number;
  created_at: string;
  updated_at: string;
  messages_count: number;
  due_at?: string | null;
  sla_status?: 'on_track' | 'warning' | 'overdue' | 'breached' | 'met' | 'none';
  sla_remaining?: { overdue: boolean; hours: number; human: string } | null;
}

interface CategoryItem {
  id: number;
  name: string;
  slug: string;
  color: string;
  is_active: boolean;
}

interface PriorityItem {
  id: number;
  name: string;
  slug: string;
  color: string;
  level: number;
  is_active: boolean;
}

interface AssignableUser {
  id: number;
  name: string;
}

interface Filters {
  search?: string;
  status?: string;
  priority?: string;
  category?: string;
  assigned_to?: number | string;
  date_from?: string;
  date_to?: string;
  sla_status?: string;
  sort_by?: string;
  sort_dir?: string;
}

interface Props {
  tickets: Ticket[];
  stats: {
    total: number;
    open: number;
    in_progress: number;
    resolved_today: number;
    overdue?: number;
  };
  categories: CategoryItem[];
  priorities: PriorityItem[];
  assignableUsers?: AssignableUser[];
  currentUserId?: number;
  filters?: Filters;
}

const statusConfig: Record<
  Ticket['status'],
  { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }
> = {
  open: { label: 'Open', variant: 'destructive' },
  in_progress: { label: 'In Progress', variant: 'default' },
  waiting: { label: 'Waiting', variant: 'secondary' },
  resolved: { label: 'Resolved', variant: 'outline' },
  closed: { label: 'Closed', variant: 'outline' },
};

const priorityColorMap: Record<string, string> = {
  gray: 'text-muted-foreground',
  amber: 'text-amber-600',
  orange: 'text-orange-600',
  red: 'text-destructive',
};

const slaConfig: Record<string, { label: string; className: string; icon?: string }> = {
  on_track: { label: 'On Track', className: 'text-success border-success/30 bg-success/5' },
  warning: { label: 'Warning', className: 'text-amber-600 border-amber-300 bg-amber-50' },
  overdue: {
    label: 'Overdue',
    className: 'text-destructive border-destructive/30 bg-destructive/5',
  },
  breached: {
    label: 'Breached',
    className: 'text-destructive border-destructive/30 bg-destructive/5',
  },
  met: { label: 'SLA Met', className: 'text-success border-success/30 bg-success/5' },
  none: { label: '', className: '' },
};

export default function TicketsIndex({
  tickets,
  stats,
  categories,
  priorities,
  assignableUsers,
  currentUserId,
  filters: initialFilters,
}: Props) {
  const cats = categories ?? [];
  const pris = priorities ?? [];
  const users = assignableUsers ?? [];
  const [showCreate, setShowCreate] = useState(false);
  const [showAdvanced, setShowAdvanced] = useState(false);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [showBulkAssign, setShowBulkAssign] = useState(false);
  const [showBulkPriority, setShowBulkPriority] = useState(false);
  const [bulkAssignTo, setBulkAssignTo] = useState('');
  const [bulkPriority, setBulkPriority] = useState('');
  const [bulkProcessing, setBulkProcessing] = useState(false);

  const [filters, setFilters] = useState<Filters>({
    search: initialFilters?.search ?? '',
    status: initialFilters?.status ?? '',
    priority: initialFilters?.priority ?? '',
    category: initialFilters?.category ?? '',
    assigned_to: initialFilters?.assigned_to ?? '',
    date_from: initialFilters?.date_from ?? '',
    date_to: initialFilters?.date_to ?? '',
    sla_status: initialFilters?.sla_status ?? '',
    sort_by: initialFilters?.sort_by ?? 'created_at',
    sort_dir: initialFilters?.sort_dir ?? 'desc',
  });

  const [searchInput, setSearchInput] = useState(filters.search ?? '');

  function applyFilters(newFilters?: Partial<Filters>) {
    const updated = { ...filters, ...newFilters };
    setFilters(updated);
    const params: Record<string, string> = {};
    Object.entries(updated).forEach(([key, value]) => {
      if (value !== '' && value !== undefined && value !== null) {
        params[key] = String(value);
      }
    });
    router.visit('/tickets', {
      data: params,
      preserveState: true,
      preserveScroll: true,
      only: ['tickets', 'stats', 'filters'],
    });
  }

  function handleSearch(e: React.FormEvent) {
    e.preventDefault();
    applyFilters({ search: searchInput });
  }

  function clearFilters() {
    setSearchInput('');
    setFilters({
      search: '',
      status: '',
      priority: '',
      category: '',
      assigned_to: '',
      date_from: '',
      date_to: '',
      sla_status: '',
      sort_by: 'created_at',
      sort_dir: 'desc',
    });
    router.visit('/tickets', {
      preserveState: true,
      preserveScroll: true,
      only: ['tickets', 'stats', 'filters'],
    });
  }

  const hasActiveFilters =
    filters.status ||
    filters.priority ||
    filters.category ||
    filters.assigned_to ||
    filters.date_from ||
    filters.date_to ||
    filters.sla_status ||
    (filters.search && filters.search !== '');

  const assignedToMe = filters.assigned_to === currentUserId?.toString();

  function toggleAssignedToMe() {
    if (assignedToMe) {
      applyFilters({ assigned_to: '' });
    } else if (currentUserId) {
      applyFilters({ assigned_to: currentUserId });
    }
  }

  function exportCsv() {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== '' && value !== undefined && value !== null) {
        params.append(key, String(value));
      }
    });
    window.location.href = `/tickets/export/csv?${params.toString()}`;
  }

  const form = useForm({
    subject: '',
    description: '',
    priority: 'medium',
    category: 'general',
    related_waybill: '',
  });

  function submitTicket(e: React.FormEvent) {
    e.preventDefault();
    form.post('/tickets', {
      onSuccess: () => {
        setShowCreate(false);
        form.reset();
        toast.success('Ticket created successfully.');
      },
      onError: () => toast.error('Failed to create ticket. Check the form fields.'),
    });
  }

  const filteredTickets = tickets;

  const allSelected = filteredTickets.length > 0 && selectedIds.length === filteredTickets.length;
  const someSelected = selectedIds.length > 0 && !allSelected;

  function toggleSelectAll() {
    if (allSelected) {
      setSelectedIds([]);
    } else {
      setSelectedIds(filteredTickets.map((t) => t.id));
    }
  }

  function toggleSelect(id: number) {
    setSelectedIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  }

  function clearSelection() {
    setSelectedIds([]);
  }

  function handleBulkClose() {
    setBulkProcessing(true);
    router.post(
      '/tickets/bulk/close',
      { ticket_ids: selectedIds },
      {
        preserveScroll: true,
        onSuccess: () => {
          toast.success(`${selectedIds.length} ticket(s) closed.`);
          clearSelection();
        },
        onError: () => toast.error('Failed to close tickets.'),
        onFinish: () => setBulkProcessing(false),
      }
    );
  }

  function handleBulkAssign(e: React.FormEvent) {
    e.preventDefault();
    if (!bulkAssignTo) {
      toast.error('Please select an assignee.');
      return;
    }
    setBulkProcessing(true);
    router.post(
      '/tickets/bulk/assign',
      { ticket_ids: selectedIds, assigned_to: Number(bulkAssignTo) },
      {
        preserveScroll: true,
        onSuccess: () => {
          toast.success(`${selectedIds.length} ticket(s) assigned.`);
          setShowBulkAssign(false);
          setBulkAssignTo('');
          clearSelection();
        },
        onError: () => toast.error('Failed to assign tickets.'),
        onFinish: () => setBulkProcessing(false),
      }
    );
  }

  function handleBulkPriority(e: React.FormEvent) {
    e.preventDefault();
    if (!bulkPriority) {
      toast.error('Please select a priority.');
      return;
    }
    setBulkProcessing(true);
    router.post(
      '/tickets/bulk/priority',
      { ticket_ids: selectedIds, priority: bulkPriority },
      {
        preserveScroll: true,
        onSuccess: () => {
          toast.success(`${selectedIds.length} ticket(s) priority updated.`);
          setShowBulkPriority(false);
          setBulkPriority('');
          clearSelection();
        },
        onError: () => toast.error('Failed to update priority.'),
        onFinish: () => setBulkProcessing(false),
      }
    );
  }

  return (
    <AppLayout>
      <Head title="Tickets" />

      <div className="space-y-4">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-xl font-bold font-display tracking-tight">Support Tickets</h1>
            <p className="text-muted-foreground">Manage customer and internal support requests</p>
          </div>
          <div className="flex items-center gap-2">
            <Button onClick={() => setShowCreate(true)}>
              <Plus className="mr-1.5 h-4 w-4" />
              Create Ticket
            </Button>
            <Button variant="outline" size="icon" asChild>
              <Link href="/tickets/analytics">
                <BarChart3 className="h-4 w-4" />
              </Link>
            </Button>
            <Button variant="outline" size="icon" onClick={exportCsv}>
              <Download className="h-4 w-4" />
            </Button>
            <Button variant="outline" size="icon" asChild>
              <Link href="/tickets/settings">
                <Settings className="h-4 w-4" />
              </Link>
            </Button>
          </div>
        </div>

        {/* Stats */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <Headphones className="h-4 w-4" /> Total Tickets
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">
                {stats?.total || tickets?.length || 0}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <AlertCircle className="h-4 w-4 text-destructive" /> Open
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display text-destructive">
                {stats?.open || 0}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <Clock className="h-4 w-4 text-info" /> In Progress
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display text-info">
                {stats?.in_progress || 0}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <CheckCircle className="h-4 w-4 text-success" /> Resolved Today
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display text-success">
                {stats?.resolved_today || 0}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <AlertTriangle className="h-4 w-4 text-destructive" /> Overdue
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display text-destructive">
                {stats?.overdue ?? 0}
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="pt-6">
            <div className="flex flex-col gap-4 md:flex-row">
              <form onSubmit={handleSearch} className="relative flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <input
                  type="text"
                  placeholder="Search subject, ticket #, or description..."
                  value={searchInput}
                  onChange={(e) => setSearchInput(e.target.value)}
                  className="flex h-10 w-full rounded-md border border-input bg-background pl-10 pr-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
              </form>
              <Select
                value={filters.status || 'all'}
                onValueChange={(v) => applyFilters({ status: v === 'all' ? '' : v })}
              >
                <SelectTrigger className="w-full md:w-[150px]">
                  <SelectValue placeholder="Status" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Status</SelectItem>
                  <SelectItem value="open">Open</SelectItem>
                  <SelectItem value="in_progress">In Progress</SelectItem>
                  <SelectItem value="waiting">Waiting</SelectItem>
                  <SelectItem value="resolved">Resolved</SelectItem>
                  <SelectItem value="closed">Closed</SelectItem>
                </SelectContent>
              </Select>
              <Button variant={assignedToMe ? 'default' : 'outline'} onClick={toggleAssignedToMe}>
                <User className="mr-1.5 h-4 w-4" />
                Assigned to Me
              </Button>
              <Button
                variant={showAdvanced ? 'default' : 'outline'}
                onClick={() => setShowAdvanced(!showAdvanced)}
              >
                <Filter className="mr-1.5 h-4 w-4" />
                Advanced
                {hasActiveFilters && (
                  <Badge variant="secondary" className="ml-1.5 h-5 px-1.5 text-xs">
                    Active
                  </Badge>
                )}
              </Button>
            </div>

            {showAdvanced && (
              <div className="mt-4 pt-4 border-t space-y-4">
                <div className="grid gap-4 md:grid-cols-3">
                  {/* Priority */}
                  <div className="space-y-1.5">
                    <label className="text-xs text-muted-foreground">Priority</label>
                    <Select
                      value={filters.priority || 'all'}
                      onValueChange={(v) => applyFilters({ priority: v === 'all' ? '' : v })}
                    >
                      <SelectTrigger className="w-full">
                        <SelectValue placeholder="All Priorities" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">All Priorities</SelectItem>
                        {pris
                          .filter((p) => p.is_active)
                          .map((p) => (
                            <SelectItem key={p.id} value={p.slug}>
                              {p.name}
                            </SelectItem>
                          ))}
                      </SelectContent>
                    </Select>
                  </div>

                  {/* Category */}
                  <div className="space-y-1.5">
                    <label className="text-xs text-muted-foreground">Category</label>
                    <Select
                      value={filters.category || 'all'}
                      onValueChange={(v) => applyFilters({ category: v === 'all' ? '' : v })}
                    >
                      <SelectTrigger className="w-full">
                        <SelectValue placeholder="All Categories" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">All Categories</SelectItem>
                        {cats
                          .filter((c) => c.is_active)
                          .map((c) => (
                            <SelectItem key={c.id} value={c.slug}>
                              {c.name}
                            </SelectItem>
                          ))}
                      </SelectContent>
                    </Select>
                  </div>

                  {/* Assignee */}
                  <div className="space-y-1.5">
                    <label className="text-xs text-muted-foreground">Assignee</label>
                    <Select
                      value={filters.assigned_to ? String(filters.assigned_to) : 'all'}
                      onValueChange={(v) => applyFilters({ assigned_to: v === 'all' ? '' : v })}
                    >
                      <SelectTrigger className="w-full">
                        <SelectValue placeholder="All Assignees" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">All Assignees</SelectItem>
                        {users.map((u) => (
                          <SelectItem key={u.id} value={String(u.id)}>
                            {u.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  {/* SLA Status */}
                  <div className="space-y-1.5">
                    <label className="text-xs text-muted-foreground">SLA Status</label>
                    <Select
                      value={filters.sla_status || 'all'}
                      onValueChange={(v) => applyFilters({ sla_status: v === 'all' ? '' : v })}
                    >
                      <SelectTrigger className="w-full">
                        <SelectValue placeholder="All SLA" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">All SLA Status</SelectItem>
                        <SelectItem value="on_track">On Track</SelectItem>
                        <SelectItem value="warning">Warning</SelectItem>
                        <SelectItem value="overdue">Overdue</SelectItem>
                        <SelectItem value="breached">Breached</SelectItem>
                        <SelectItem value="met">SLA Met</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  {/* Date From */}
                  <div className="space-y-1.5">
                    <label className="text-xs text-muted-foreground">Date From</label>
                    <Input
                      type="date"
                      value={filters.date_from ?? ''}
                      onChange={(e) => applyFilters({ date_from: e.target.value })}
                    />
                  </div>

                  {/* Date To */}
                  <div className="space-y-1.5">
                    <label className="text-xs text-muted-foreground">Date To</label>
                    <Input
                      type="date"
                      value={filters.date_to ?? ''}
                      onChange={(e) => applyFilters({ date_to: e.target.value })}
                    />
                  </div>
                </div>

                {/* Sort + Clear */}
                <div className="flex items-center justify-between gap-4">
                  <div className="flex items-center gap-2">
                    <label className="text-xs text-muted-foreground">Sort by:</label>
                    <Select
                      value={filters.sort_by ?? 'created_at'}
                      onValueChange={(v) => applyFilters({ sort_by: v })}
                    >
                      <SelectTrigger className="w-[140px]">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="created_at">Created Date</SelectItem>
                        <SelectItem value="updated_at">Updated Date</SelectItem>
                        <SelectItem value="due_at">Due Date</SelectItem>
                        <SelectItem value="priority">Priority</SelectItem>
                        <SelectItem value="subject">Subject</SelectItem>
                      </SelectContent>
                    </Select>
                    <Select
                      value={filters.sort_dir ?? 'desc'}
                      onValueChange={(v) => applyFilters({ sort_dir: v })}
                    >
                      <SelectTrigger className="w-[100px]">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="desc">Descending</SelectItem>
                        <SelectItem value="asc">Ascending</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  {hasActiveFilters && (
                    <Button variant="ghost" size="sm" onClick={clearFilters}>
                      Clear All Filters
                    </Button>
                  )}
                </div>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Bulk Action Bar */}
        {selectedIds.length > 0 && (
          <Card className="border-primary/30 bg-primary/5">
            <CardContent className="py-3">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-3">
                  <span className="text-sm font-medium">{selectedIds.length} selected</span>
                  <Button variant="ghost" size="sm" onClick={clearSelection}>
                    Clear
                  </Button>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <Button variant="outline" size="sm" onClick={() => setShowBulkAssign(true)}>
                    <User className="mr-1.5 h-3.5 w-3.5" />
                    Bulk Assign
                  </Button>
                  <Button variant="outline" size="sm" onClick={() => setShowBulkPriority(true)}>
                    <Tag className="mr-1.5 h-3.5 w-3.5" />
                    Change Priority
                  </Button>
                  <Button
                    variant="destructive"
                    size="sm"
                    onClick={handleBulkClose}
                    disabled={bulkProcessing}
                  >
                    <XCircle className="mr-1.5 h-3.5 w-3.5" />
                    Bulk Close
                  </Button>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Tickets List */}
        <Card>
          <CardContent className="p-0">
            {filteredTickets.length > 0 && (
              <div className="flex items-center gap-3 px-4 py-2 border-b bg-muted/30">
                <input
                  type="checkbox"
                  checked={allSelected}
                  ref={(el) => {
                    if (el) el.indeterminate = someSelected;
                  }}
                  onChange={toggleSelectAll}
                  className="h-4 w-4 rounded border-input"
                />
                <span className="text-xs text-muted-foreground">
                  {allSelected ? 'Deselect all' : 'Select all'}
                </span>
              </div>
            )}
            <div className="divide-y">
              {filteredTickets.length > 0 ? (
                filteredTickets.map((ticket) => {
                  const statusCfg = statusConfig[ticket.status];
                  const priItem = pris.find((p) => p.slug === ticket.priority);
                  const priColor = priItem
                    ? priorityColorMap[priItem.color] || 'text-muted-foreground'
                    : 'text-muted-foreground';
                  const priLabel = priItem ? priItem.name : ticket.priority;
                  const isSelected = selectedIds.includes(ticket.id);
                  return (
                    <div
                      key={ticket.id}
                      className={`flex items-start gap-4 p-4 hover:bg-muted/50 transition-colors ${
                        isSelected ? 'bg-primary/5' : ''
                      }`}
                    >
                      <input
                        type="checkbox"
                        checked={isSelected}
                        onChange={() => toggleSelect(ticket.id)}
                        onClick={(e) => e.stopPropagation()}
                        className="mt-2 h-4 w-4 rounded border-input"
                      />
                      <div
                        className={`mt-1 p-2 rounded-full cursor-pointer ${
                          priItem?.color === 'red'
                            ? 'bg-destructive/10'
                            : priItem?.color === 'orange'
                              ? 'bg-orange-100'
                              : priItem?.color === 'amber'
                                ? 'bg-amber-100'
                                : 'bg-muted'
                        }`}
                        onClick={() => router.visit(`/tickets/${ticket.id}`)}
                      >
                        <Headphones className={`h-4 w-4 ${priColor}`} />
                      </div>

                      <div
                        className="flex-1 min-w-0 cursor-pointer"
                        onClick={() => router.visit(`/tickets/${ticket.id}`)}
                      >
                        <div className="flex items-center gap-2">
                          <span className="font-mono text-sm text-muted-foreground">
                            #{ticket.ticket_number}
                          </span>
                          <Badge variant={statusCfg.variant}>{statusCfg.label}</Badge>
                          <Badge variant="outline" className={priColor}>
                            {priLabel}
                          </Badge>
                        </div>

                        <h3 className="mt-1 font-medium truncate">{ticket.subject}</h3>

                        <p className="mt-1 text-sm text-muted-foreground line-clamp-2">
                          {ticket.description}
                        </p>

                        <div className="mt-2 flex items-center gap-4 text-sm text-muted-foreground">
                          <div className="flex items-center gap-1">
                            <User className="h-3 w-3" />
                            {ticket.created_by.name}
                          </div>
                          <div className="flex items-center gap-1">
                            <Clock className="h-3 w-3" />
                            {formatDate(ticket.created_at)}
                          </div>
                          <div className="flex items-center gap-1">
                            <MessageSquare className="h-3 w-3" />
                            {ticket.messages_count} messages
                          </div>
                          {ticket.related_waybill && (
                            <Badge variant="outline" className="text-xs">
                              WB: {ticket.related_waybill}
                            </Badge>
                          )}
                          {ticket.sla_status && ticket.sla_status !== 'none' && (
                            <Badge
                              variant="outline"
                              className={`text-xs ${slaConfig[ticket.sla_status]?.className ?? ''}`}
                            >
                              <Timer className="mr-1 h-3 w-3" />
                              {ticket.sla_remaining?.human ?? slaConfig[ticket.sla_status].label}
                            </Badge>
                          )}
                        </div>
                      </div>

                      <div className="flex items-center gap-2">
                        {ticket.assigned_to && (
                          <div className="text-right text-sm">
                            <div className="text-muted-foreground">Assigned to</div>
                            <div className="font-medium">{ticket.assigned_to.name}</div>
                          </div>
                        )}
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon">
                              <MoreHorizontal className="h-4 w-4" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={() => router.visit(`/tickets/${ticket.id}`)}>
                              <Eye className="mr-1.5 h-4 w-4" />
                              View Details
                            </DropdownMenuItem>
                            <DropdownMenuItem>
                              <MessageSquare className="mr-1.5 h-4 w-4" />
                              Reply
                            </DropdownMenuItem>
                            <DropdownMenuItem>
                              <CheckCircle className="mr-1.5 h-4 w-4" />
                              Mark Resolved
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </div>
                    </div>
                  );
                })
              ) : (
                <div className="flex flex-col items-center justify-center py-12">
                  <Headphones className="h-12 w-12 text-muted-foreground/50" />
                  <h3 className="mt-4 text-lg font-semibold">No tickets found</h3>
                  <p className="text-muted-foreground">
                    {hasActiveFilters
                      ? 'No tickets match the current filters. Try clearing some filters.'
                      : 'All caught up! No support tickets to review.'}
                  </p>
                </div>
              )}
            </div>
          </CardContent>
        </Card>
      </div>
      {/* Create Ticket Dialog */}
      <Dialog open={showCreate} onOpenChange={setShowCreate}>
        <DialogContent className="sm:max-w-[500px]">
          <DialogHeader>
            <DialogTitle>Create Support Ticket</DialogTitle>
            <DialogDescription>Submit a new support or internal request ticket.</DialogDescription>
          </DialogHeader>
          <form onSubmit={submitTicket} className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="subject">Subject *</Label>
              <Input
                id="subject"
                value={form.data.subject}
                onChange={(e) => form.setData('subject', e.target.value)}
                placeholder="Brief summary of the issue"
                required
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-1.5">
                <Label>Priority *</Label>
                <Select
                  value={form.data.priority}
                  onValueChange={(v) => form.setData('priority', v)}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {pris
                      .filter((p) => p.is_active)
                      .map((p) => (
                        <SelectItem key={p.id} value={p.slug}>
                          {p.name}
                        </SelectItem>
                      ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1.5">
                <Label>Category *</Label>
                <Select
                  value={form.data.category}
                  onValueChange={(v) => form.setData('category', v)}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {cats
                      .filter((c) => c.is_active)
                      .map((c) => (
                        <SelectItem key={c.id} value={c.slug}>
                          {c.name}
                        </SelectItem>
                      ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="related_waybill">Related Waybill (optional)</Label>
              <Input
                id="related_waybill"
                value={form.data.related_waybill}
                onChange={(e) => form.setData('related_waybill', e.target.value)}
                placeholder="e.g. WB-2026-00123"
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="description">Description</Label>
              <Textarea
                id="description"
                value={form.data.description}
                onChange={(e) => form.setData('description', e.target.value)}
                placeholder="Provide details about the issue..."
                rows={4}
              />
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setShowCreate(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={form.processing}>
                {form.processing ? 'Creating...' : 'Create Ticket'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Bulk Assign Dialog */}
      <Dialog open={showBulkAssign} onOpenChange={setShowBulkAssign}>
        <DialogContent className="sm:max-w-[400px]">
          <DialogHeader>
            <DialogTitle>Bulk Assign Tickets</DialogTitle>
            <DialogDescription>
              Assign {selectedIds.length} selected ticket(s) to a team member.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleBulkAssign} className="space-y-4">
            <div className="space-y-1.5">
              <Label>Assign To *</Label>
              <Select value={bulkAssignTo} onValueChange={setBulkAssignTo}>
                <SelectTrigger>
                  <SelectValue placeholder="Select team member" />
                </SelectTrigger>
                <SelectContent>
                  {users.map((u) => (
                    <SelectItem key={u.id} value={String(u.id)}>
                      {u.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setShowBulkAssign(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={bulkProcessing}>
                {bulkProcessing ? 'Assigning...' : 'Assign'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Bulk Priority Change Dialog */}
      <Dialog open={showBulkPriority} onOpenChange={setShowBulkPriority}>
        <DialogContent className="sm:max-w-[400px]">
          <DialogHeader>
            <DialogTitle>Bulk Change Priority</DialogTitle>
            <DialogDescription>
              Change priority for {selectedIds.length} selected ticket(s). Due dates will be
              recalculated.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleBulkPriority} className="space-y-4">
            <div className="space-y-1.5">
              <Label>New Priority *</Label>
              <Select value={bulkPriority} onValueChange={setBulkPriority}>
                <SelectTrigger>
                  <SelectValue placeholder="Select priority" />
                </SelectTrigger>
                <SelectContent>
                  {pris
                    .filter((p) => p.is_active)
                    .map((p) => (
                      <SelectItem key={p.id} value={p.slug}>
                        {p.name}
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setShowBulkPriority(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={bulkProcessing}>
                {bulkProcessing ? 'Updating...' : 'Update Priority'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
