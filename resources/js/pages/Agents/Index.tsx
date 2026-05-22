import { Head, useForm, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import {
  Users,
  UserPlus,
  Search,
  MoreHorizontal,
  Edit,
  Eye,
  UserX,
  Trash2,
  UserCheck,
  TrendingUp,
  Clock,
  Phone,
  Mail,
  Activity,
  X,
  Package,
  Plus,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import type { User, AgentProfile } from '@/types';

interface Agent extends User {
  phone?: string;
  profile?: AgentProfile;
  agentProfile?: AgentProfile;
  stats?: {
    leads_today: number;
    sales_today: number;
    conversion_rate: number;
    active_leads: number;
  };
}

interface Props {
  agents: Agent[];
  stats: {
    total: number;
    active: number;
    inactive: number;
    avg_performance: number;
  };
}

function AddAgentModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { data, setData, post, processing, errors, reset } = useForm({
    name: '',
    email: '',
    password: '',
    phone: '',
  });

  function submit(e: React.FormEvent) {
    e.preventDefault();
    post('/agents', {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  }

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="w-full max-w-md rounded-lg bg-background p-6 shadow-xl">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold">Create Agent Account</h2>
          <button onClick={onClose} className="text-muted-foreground hover:text-foreground">
            <X className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={submit} className="space-y-4">
          <div>
            <label className="text-sm font-medium">Full Name</label>
            <input
              type="text"
              value={data.name}
              onChange={e => setData('name', e.target.value)}
              placeholder="Juan dela Cruz"
              className="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            {errors.name && <p className="mt-1 text-xs text-destructive">{errors.name}</p>}
          </div>

          <div>
            <label className="text-sm font-medium">Email Address</label>
            <input
              type="email"
              value={data.email}
              onChange={e => setData('email', e.target.value)}
              placeholder="agent@company.com"
              className="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            {errors.email && <p className="mt-1 text-xs text-destructive">{errors.email}</p>}
          </div>

          <div>
            <label className="text-sm font-medium">Password</label>
            <input
              type="password"
              value={data.password}
              onChange={e => setData('password', e.target.value)}
              placeholder="Min 8 characters"
              className="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            {errors.password && <p className="mt-1 text-xs text-destructive">{errors.password}</p>}
          </div>

          <div>
            <label className="text-sm font-medium">Phone <span className="text-muted-foreground">(optional)</span></label>
            <input
              type="text"
              value={data.phone}
              onChange={e => setData('phone', e.target.value)}
              placeholder="+63 9XX XXX XXXX"
              className="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            {errors.phone && <p className="mt-1 text-xs text-destructive">{errors.phone}</p>}
          </div>

          <div className="flex gap-3 pt-2">
            <Button type="button" variant="outline" className="flex-1" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" className="flex-1" disabled={processing}>
              {processing ? 'Creating...' : 'Create Account'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}

function EditProfileModal({ agent, open, onClose }: { agent: Agent | null; open: boolean; onClose: () => void }) {
  const [skills, setSkills] = useState<string[]>([]);
  const [skillInput, setSkillInput] = useState('');
  const [maxCycles, setMaxCycles] = useState(10);
  const [saving, setSaving] = useState(false);

  // Sync state when agent changes
  useEffect(() => {
    if (agent) {
      setSkills(agent.agentProfile?.product_skills ?? []);
      setMaxCycles(agent.agentProfile?.max_active_cycles ?? 10);
    }
  }, [agent]);

  if (!open || !agent) return null;

  const addSkill = () => {
    const trimmed = skillInput.trim();
    if (trimmed && !skills.includes(trimmed)) {
      setSkills([...skills, trimmed]);
    }
    setSkillInput('');
  };

  const removeSkill = (skill: string) => setSkills(skills.filter((s) => s !== skill));

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter') { e.preventDefault(); addSkill(); }
  };

  const handleSave = () => {
    setSaving(true);
    router.patch(`/agents/${agent.id}/profile`, {
      product_skills: skills,
      max_active_cycles: maxCycles,
    }, {
      onFinish: () => { setSaving(false); onClose(); },
    });
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="w-full max-w-md rounded-lg bg-background p-6 shadow-xl">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold flex items-center gap-2">
            <Package className="h-5 w-5" />
            Product Skills — {agent.name}
          </h2>
          <button onClick={onClose} className="text-muted-foreground hover:text-foreground">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="space-y-4">
          <div>
            <label className="text-sm font-medium">Product Lines</label>
            <p className="text-xs text-muted-foreground mb-2">
              Agent will only receive leads matching these products (e.g. "STEM Coffee", "Mullein Inhaler")
            </p>
            <div className="flex gap-2">
              <input
                type="text"
                value={skillInput}
                onChange={(e) => setSkillInput(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder="Type product name + Enter"
                className="flex h-9 flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
              <button
                type="button"
                onClick={addSkill}
                className="flex h-9 w-9 items-center justify-center rounded-md border border-input bg-background hover:bg-muted"
              >
                <Plus className="h-4 w-4" />
              </button>
            </div>
            <div className="mt-2 flex flex-wrap gap-2 min-h-[36px]">
              {skills.length === 0 && (
                <span className="text-xs text-muted-foreground italic">No products set — agent pulls any available lead</span>
              )}
              {skills.map((skill) => (
                <span
                  key={skill}
                  className="flex items-center gap-1 rounded-full bg-primary/10 px-3 py-1 text-sm font-medium"
                >
                  {skill}
                  <button onClick={() => removeSkill(skill)} className="ml-1 text-muted-foreground hover:text-destructive">
                    <X className="h-3 w-3" />
                  </button>
                </span>
              ))}
            </div>
          </div>

          <div>
            <label className="text-sm font-medium">Max Active Leads</label>
            <input
              type="number"
              min={1}
              max={50}
              value={maxCycles}
              onChange={(e) => setMaxCycles(Number(e.target.value))}
              className="mt-1 flex h-9 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
          </div>

          <div className="flex gap-3 pt-2">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 rounded-md border border-input bg-background px-4 py-2 text-sm hover:bg-muted"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleSave}
              disabled={saving}
              className="flex-1 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
            >
              {saving ? 'Saving...' : 'Save'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

export default function AgentsIndex({ agents, stats }: Props) {
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [showAddModal, setShowAddModal] = useState(false);
  const [editingAgent, setEditingAgent] = useState<Agent | null>(null);

  const filteredAgents = agents?.filter(agent => {
    const matchesSearch = agent.name.toLowerCase().includes(search.toLowerCase()) ||
      agent.email.toLowerCase().includes(search.toLowerCase());
    const matchesStatus = statusFilter === 'all' ||
      (statusFilter === 'active' && agent.is_active) ||
      (statusFilter === 'inactive' && !agent.is_active);
    return matchesSearch && matchesStatus;
  }) || [];

  const getInitials = (name: string) => {
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
  };

  function toggleActive(agent: Agent) {
    router.patch(`/agents/${agent.id}/toggle-active`);
  }

  function deleteAgent(agent: Agent) {
    if (confirm(`Delete agent ${agent.name}? This cannot be undone.`)) {
      router.post(`/agents/${agent.id}/delete`);
    }
  }

  return (
    <AppLayout>
      <Head title="Agents" />

      <AddAgentModal open={showAddModal} onClose={() => setShowAddModal(false)} />
      <EditProfileModal agent={editingAgent} open={editingAgent !== null} onClose={() => setEditingAgent(null)} />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Agent Management</h1>
            <p className="text-muted-foreground">
              Manage team members and monitor performance
            </p>
          </div>
          <Button onClick={() => setShowAddModal(true)}>
            <UserPlus className="mr-2 h-4 w-4" />
            Add Agent
          </Button>
        </div>

        {/* Stats */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <Users className="h-4 w-4" /> Total Agents
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats?.total || agents?.length || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <Activity className="h-4 w-4 text-green-600" /> Active
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">{stats?.active || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <Clock className="h-4 w-4 text-gray-600" /> Inactive
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-gray-600">{stats?.inactive || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <TrendingUp className="h-4 w-4" /> Avg Performance
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats?.avg_performance || 0}%</div>
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
                  placeholder="Search agents by name or email..."
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
                  <SelectItem value="active">Active</SelectItem>
                  <SelectItem value="inactive">Inactive</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </CardContent>
        </Card>

        {/* Agents Grid */}
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {filteredAgents.length > 0 ? (
            filteredAgents.map((agent) => (
              <Card key={agent.id} className="relative">
                <CardContent className="pt-6">
                  <div className="absolute right-4 top-4">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon">
                          <MoreHorizontal className="h-4 w-4" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem>
                          <Eye className="mr-2 h-4 w-4" />
                          View Profile
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => setEditingAgent(agent)}>
                          <Edit className="mr-2 h-4 w-4" />
                          Edit
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                          onClick={() => toggleActive(agent)}
                          className={agent.is_active ? 'text-destructive' : 'text-green-600'}
                        >
                          {agent.is_active ? (
                            <>
                              <UserX className="mr-2 h-4 w-4" />
                              Deactivate
                            </>
                          ) : (
                            <>
                              <UserCheck className="mr-2 h-4 w-4" />
                              Activate
                            </>
                          )}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                          onClick={() => deleteAgent(agent)}
                          className="text-destructive"
                        >
                          <Trash2 className="mr-2 h-4 w-4" />
                          Delete
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>

                  <div className="flex flex-col items-center text-center">
                    <Avatar className="h-16 w-16">
                      <AvatarImage src={(agent as any).avatar_url} />
                      <AvatarFallback className="bg-primary text-primary-foreground text-lg">
                        {getInitials(agent.name)}
                      </AvatarFallback>
                    </Avatar>
                    <h3 className="mt-3 font-semibold">{agent.name}</h3>
                    <Badge variant={agent.is_active ? 'default' : 'secondary'} className="mt-1">
                      {agent.is_active ? 'Active' : 'Inactive'}
                    </Badge>

                    <div className="mt-4 flex items-center gap-4 text-sm text-muted-foreground">
                      <div className="flex items-center gap-1">
                        <Mail className="h-4 w-4" />
                        <span className="truncate max-w-[120px]">{agent.email}</span>
                      </div>
                    </div>

                    {agent.phone && (
                      <div className="mt-1 flex items-center gap-1 text-sm text-muted-foreground">
                        <Phone className="h-4 w-4" />
                        <span>{agent.phone}</span>
                      </div>
                    )}

                    {/* Performance Stats */}
                    <div className="mt-4 w-full grid grid-cols-3 gap-2 border-t pt-4">
                      <div className="text-center">
                        <div className="text-lg font-semibold">{agent.stats?.leads_today || 0}</div>
                        <div className="text-xs text-muted-foreground">Leads</div>
                      </div>
                      <div className="text-center">
                        <div className="text-lg font-semibold text-green-600">{agent.stats?.sales_today || 0}</div>
                        <div className="text-xs text-muted-foreground">Sales</div>
                      </div>
                      <div className="text-center">
                        <div className="text-lg font-semibold">{agent.stats?.conversion_rate || 0}%</div>
                        <div className="text-xs text-muted-foreground">Rate</div>
                      </div>
                    </div>

                    {/* Performance Bar */}
                    <div className="mt-3 w-full">
                      <div className="flex items-center justify-between text-xs mb-1">
                        <span className="text-muted-foreground">Performance</span>
                        <span className="font-medium">{agent.agentProfile?.performance_score || 50}%</span>
                      </div>
                      <div className="h-2 rounded-full bg-muted">
                        <div
                          className={`h-full rounded-full ${
                            (agent.agentProfile?.performance_score || 50) >= 70
                              ? 'bg-green-600'
                              : (agent.agentProfile?.performance_score || 50) >= 40
                              ? 'bg-yellow-600'
                              : 'bg-red-600'
                          }`}
                          style={{ width: `${agent.agentProfile?.performance_score || 50}%` }}
                        />
                      </div>
                    </div>

                    {/* Skills */}
                    {agent.agentProfile?.product_skills && agent.agentProfile.product_skills.length > 0 && (
                      <div className="mt-3 flex flex-wrap gap-1 justify-center">
                        {agent.agentProfile.product_skills.slice(0, 3).map((skill, i) => (
                          <Badge key={i} variant="outline" className="text-xs">
                            {skill}
                          </Badge>
                        ))}
                        {agent.agentProfile.product_skills.length > 3 && (
                          <Badge variant="outline" className="text-xs">
                            +{agent.agentProfile.product_skills.length - 3}
                          </Badge>
                        )}
                      </div>
                    )}
                  </div>
                </CardContent>
              </Card>
            ))
          ) : (
            <Card className="col-span-full">
              <CardContent className="flex flex-col items-center justify-center py-12">
                <Users className="h-12 w-12 text-muted-foreground/50" />
                <h3 className="mt-4 text-lg font-semibold">No agents found</h3>
                <p className="text-muted-foreground">
                  {search || statusFilter !== 'all' ? 'Try adjusting your filters' : 'Add your first agent to get started'}
                </p>
                {!search && statusFilter === 'all' && (
                  <Button className="mt-4" onClick={() => setShowAddModal(true)}>
                    <UserPlus className="mr-2 h-4 w-4" />
                    Add First Agent
                  </Button>
                )}
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </AppLayout>
  );
}
