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
  Timer,
  AlertTriangle,
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
  currentUserId?: number;
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
  currentUserId,
}: Props) {
  const cats = categories ?? [];
  const pris = priorities ?? [];
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [assignedToMe, setAssignedToMe] = useState(false);
  const [showCreate, setShowCreate] = useState(false);

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

  const filteredTickets = tickets.filter((t) => {
    const matchesSearch =
      !search ||
      t.subject.toLowerCase().includes(search.toLowerCase()) ||
      t.ticket_number.toLowerCase().includes(search.toLowerCase());
    const matchesStatus = statusFilter === 'all' || t.status === statusFilter;
    const matchesAssigned = !assignedToMe || (currentUserId && t.assigned_to?.id === currentUserId);
    return matchesSearch && matchesStatus && matchesAssigned;
  });

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
              <div className="relative flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <input
                  type="text"
                  placeholder="Search tickets..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  className="flex h-10 w-full rounded-md border border-input bg-background pl-10 pr-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
              </div>
              <Select value={statusFilter} onValueChange={setStatusFilter}>
                <SelectTrigger className="w-full md:w-[150px]">
                  <SelectValue placeholder="Status" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Status</SelectItem>
                  <SelectItem value="open">Open</SelectItem>
                  <SelectItem value="in_progress">In Progress</SelectItem>
                  <SelectItem value="waiting">Waiting</SelectItem>
                  <SelectItem value="resolved">Resolved</SelectItem>
                </SelectContent>
              </Select>
              <Button
                variant={assignedToMe ? 'default' : 'outline'}
                onClick={() => setAssignedToMe(!assignedToMe)}
              >
                <User className="mr-1.5 h-4 w-4" />
                Assigned to Me
              </Button>
              <Button variant="outline">
                <Filter className="mr-1.5 h-4 w-4" />
                Filter
              </Button>
            </div>
          </CardContent>
        </Card>

        {/* Tickets List */}
        <Card>
          <CardContent className="p-0">
            <div className="divide-y">
              {filteredTickets.length > 0 ? (
                filteredTickets.map((ticket) => {
                  const statusCfg = statusConfig[ticket.status];
                  const priItem = pris.find((p) => p.slug === ticket.priority);
                  const priColor = priItem
                    ? priorityColorMap[priItem.color] || 'text-muted-foreground'
                    : 'text-muted-foreground';
                  const priLabel = priItem ? priItem.name : ticket.priority;
                  return (
                    <div
                      key={ticket.id}
                      className="flex items-start gap-4 p-4 hover:bg-muted/50 transition-colors cursor-pointer"
                      onClick={() => router.visit(`/tickets/${ticket.id}`)}
                    >
                      <div
                        className={`mt-1 p-2 rounded-full ${
                          priItem?.color === 'red'
                            ? 'bg-destructive/10'
                            : priItem?.color === 'orange'
                              ? 'bg-orange-100'
                              : priItem?.color === 'amber'
                                ? 'bg-amber-100'
                                : 'bg-muted'
                        }`}
                      >
                        <Headphones className={`h-4 w-4 ${priColor}`} />
                      </div>

                      <div className="flex-1 min-w-0">
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
                    {assignedToMe
                      ? 'No tickets assigned to you.'
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
    </AppLayout>
  );
}
