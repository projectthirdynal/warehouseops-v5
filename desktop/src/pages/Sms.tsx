import { useState, useEffect } from 'react';
import {
  MessageSquare,
  Send,
  Users,
  FileText,
  Clock,
  CheckCircle,
  XCircle,
  Loader2,
  RefreshCw,
  Plus,
  Search,
  ChevronDown,
  ChevronUp,
  AlertCircle,
  Eye,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';

interface SmsTemplate {
  id: number;
  name: string;
  message: string;
  category: string | null;
  variables: string[];
}

interface SmsCampaign {
  id: number;
  name: string;
  message: string;
  type: string;
  status: string;
  target_audience: string;
  total_recipients: number;
  sent_count: number;
  failed_count: number;
  delivered_count: number;
  created_at: string;
}

interface SmsLogEntry {
  id: number;
  phone: string;
  message: string;
  status: string;
  campaign_name: string | null;
  error_message: string | null;
  sent_at: string | null;
  created_at: string;
}

interface SmsStats {
  total_sent: number;
  total_failed: number;
  active_campaigns: number;
  active_sequences: number;
  total_templates: number;
}

type Tab = 'campaigns' | 'compose' | 'templates' | 'logs';

const VARIABLES = [
  { key: '{name}', label: 'Name' },
  { key: '{waybill}', label: 'Waybill #' },
  { key: '{status}', label: 'Status' },
  { key: '{amount}', label: 'Amount' },
  { key: '{cod}', label: 'COD' },
  { key: '{tracking}', label: 'Tracking' },
];

export default function Sms() {
  const [activeTab, setActiveTab] = useState<Tab>('campaigns');
  const [loading, setLoading] = useState(true);
  const [stats, setStats] = useState<SmsStats | null>(null);
  const [campaigns, setCampaigns] = useState<SmsCampaign[]>([]);
  const [templates, setTemplates] = useState<SmsTemplate[]>([]);
  const [logs, setLogs] = useState<SmsLogEntry[]>([]);

  // Compose state
  const [campaignName, setCampaignName] = useState('');
  const [composeMessage, setComposeMessage] = useState('');
  const [targetAudience, setTargetAudience] = useState('all_customers');
  const [selectedTemplate, setSelectedTemplate] = useState<number | null>(null);
  const [isSending, setIsSending] = useState(false);
  const [sendResult, setSendResult] = useState<{ success: boolean; message: string } | null>(null);
  const [recipientPreview, setRecipientPreview] = useState<{ count: number; samples: string[] } | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);

  // Quick send state
  const [quickPhone, setQuickPhone] = useState('');
  const [quickMessage, setQuickMessage] = useState('');
  const [quickSending, setQuickSending] = useState(false);

  // Template create state
  const [showCreateTemplate, setShowCreateTemplate] = useState(false);
  const [newTemplateName, setNewTemplateName] = useState('');
  const [newTemplateMessage, setNewTemplateMessage] = useState('');
  const [newTemplateCategory, setNewTemplateCategory] = useState('notification');

  // Log filters
  const [logSearch, setLogSearch] = useState('');
  const [logStatus, setLogStatus] = useState('');

  // Campaign detail
  const [viewingCampaign, setViewingCampaign] = useState<SmsCampaign | null>(null);

  const fetchData = async () => {
    try {
      const data = await api.getSmsData();
      setStats(data.stats);
      setCampaigns(data.campaigns || []);
      setTemplates(data.templates || []);
      setLogs(data.logs || []);
    } catch {
      // handled by interceptor
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, []);

  const handlePreviewRecipients = async () => {
    setPreviewLoading(true);
    try {
      const data = await api.smsPreviewRecipients(targetAudience);
      setRecipientPreview(data);
    } catch {
      setRecipientPreview(null);
    } finally {
      setPreviewLoading(false);
    }
  };

  const handleSendCampaign = async () => {
    if (!campaignName.trim() || !composeMessage.trim()) return;
    setIsSending(true);
    setSendResult(null);
    try {
      const result = await api.smsSendCampaign({
        name: campaignName,
        message: composeMessage,
        target_audience: targetAudience,
      });
      setSendResult({ success: true, message: result.message });
      setCampaignName('');
      setComposeMessage('');
      setRecipientPreview(null);
      fetchData();
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string } } };
      setSendResult({ success: false, message: axiosErr?.response?.data?.message || 'Failed to send campaign' });
    } finally {
      setIsSending(false);
    }
  };

  const handleQuickSend = async () => {
    if (!quickPhone.trim() || !quickMessage.trim()) return;
    setQuickSending(true);
    try {
      await api.smsQuickSend(quickPhone, quickMessage);
      setQuickPhone('');
      setQuickMessage('');
      fetchData();
    } catch {
      // silent
    } finally {
      setQuickSending(false);
    }
  };

  const handleCreateTemplate = async () => {
    if (!newTemplateName.trim() || !newTemplateMessage.trim()) return;
    try {
      await api.smsCreateTemplate({
        name: newTemplateName,
        message: newTemplateMessage,
        category: newTemplateCategory,
      });
      setNewTemplateName('');
      setNewTemplateMessage('');
      setShowCreateTemplate(false);
      fetchData();
    } catch {
      // silent
    }
  };

  const handleDeleteTemplate = async (id: number) => {
    try {
      await api.smsDeleteTemplate(id);
      fetchData();
    } catch {
      // silent
    }
  };

  const insertVariable = (variable: string, target: 'compose' | 'quick' | 'template') => {
    if (target === 'compose') setComposeMessage(prev => prev + variable);
    else if (target === 'quick') setQuickMessage(prev => prev + variable);
    else setNewTemplateMessage(prev => prev + variable);
  };

  const charCount = composeMessage.length;
  const smsSegments = Math.ceil(charCount / 160) || 1;

  const filteredLogs = logs.filter(log => {
    if (logSearch && !log.phone.includes(logSearch) && !log.message.toLowerCase().includes(logSearch.toLowerCase())) return false;
    if (logStatus && log.status !== logStatus) return false;
    return true;
  });

  if (loading) {
    return (
      <div className="flex items-center justify-center h-[60vh]">
        <Loader2 className="h-8 w-8 animate-spin text-primary" />
      </div>
    );
  }

  const tabs: { key: Tab; label: string; icon: React.ComponentType<{ className?: string }> }[] = [
    { key: 'campaigns', label: 'Campaigns', icon: MessageSquare },
    { key: 'compose', label: 'Compose', icon: Send },
    { key: 'templates', label: 'Templates', icon: FileText },
    { key: 'logs', label: 'Logs', icon: Clock },
  ];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">SMS Messaging</h1>
          <p className="text-muted-foreground">Send bulk SMS campaigns and manage templates</p>
        </div>
        <Button variant="outline" size="sm" onClick={fetchData}>
          <RefreshCw className="mr-2 h-4 w-4" /> Refresh
        </Button>
      </div>

      {/* Stats */}
      <div className="grid gap-4 md:grid-cols-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Total Sent</CardTitle>
            <Send className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent><div className="text-2xl font-bold">{stats?.total_sent || 0}</div></CardContent>
        </Card>
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Failed</CardTitle>
            <XCircle className="h-4 w-4 text-red-500" />
          </CardHeader>
          <CardContent><div className="text-2xl font-bold text-red-600">{stats?.total_failed || 0}</div></CardContent>
        </Card>
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Active Campaigns</CardTitle>
            <MessageSquare className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent><div className="text-2xl font-bold">{stats?.active_campaigns || 0}</div></CardContent>
        </Card>
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Templates</CardTitle>
            <FileText className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent><div className="text-2xl font-bold">{stats?.total_templates || 0}</div></CardContent>
        </Card>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 border-b">
        {tabs.map(tab => (
          <button
            key={tab.key}
            onClick={() => setActiveTab(tab.key)}
            className={`flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
              activeTab === tab.key
                ? 'border-primary text-primary'
                : 'border-transparent text-muted-foreground hover:text-foreground'
            }`}
          >
            <tab.icon className="h-4 w-4" />
            {tab.label}
          </button>
        ))}
      </div>

      {/* === CAMPAIGNS TAB === */}
      {activeTab === 'campaigns' && (
        <div className="space-y-4">
          {campaigns.length === 0 ? (
            <Card>
              <CardContent className="flex flex-col items-center justify-center py-12">
                <MessageSquare className="h-12 w-12 text-muted-foreground/50" />
                <p className="mt-4 text-lg font-medium text-muted-foreground">No campaigns yet</p>
                <p className="text-sm text-muted-foreground">Go to Compose tab to create your first campaign</p>
                <Button className="mt-4" onClick={() => setActiveTab('compose')}>
                  <Plus className="mr-2 h-4 w-4" /> Create Campaign
                </Button>
              </CardContent>
            </Card>
          ) : (
            campaigns.map(campaign => (
              <Card key={campaign.id}>
                <CardContent className="p-4">
                  <div className="flex items-center justify-between">
                    <div className="flex-1">
                      <div className="flex items-center gap-3">
                        <h3 className="font-semibold">{campaign.name}</h3>
                        <Badge variant={
                          campaign.status === 'completed' ? 'success'
                            : campaign.status === 'sending' ? 'info'
                            : campaign.status === 'failed' ? 'destructive'
                            : 'secondary'
                        }>
                          {campaign.status}
                        </Badge>
                        <Badge variant="outline">{campaign.type}</Badge>
                      </div>
                      <p className="text-sm text-muted-foreground mt-1 line-clamp-1">{campaign.message}</p>
                      <div className="flex items-center gap-4 mt-2 text-sm text-muted-foreground">
                        <span><Users className="inline h-3 w-3 mr-1" />{campaign.total_recipients} recipients</span>
                        <span className="text-green-600"><CheckCircle className="inline h-3 w-3 mr-1" />{campaign.sent_count} sent</span>
                        {campaign.failed_count > 0 && (
                          <span className="text-red-600"><XCircle className="inline h-3 w-3 mr-1" />{campaign.failed_count} failed</span>
                        )}
                        <span>{new Date(campaign.created_at).toLocaleDateString()}</span>
                      </div>
                    </div>
                    <Button variant="ghost" size="sm" onClick={() => setViewingCampaign(viewingCampaign?.id === campaign.id ? null : campaign)}>
                      <Eye className="h-4 w-4" />
                    </Button>
                  </div>
                  {viewingCampaign?.id === campaign.id && (
                    <div className="mt-4 p-3 bg-muted rounded-lg">
                      <p className="text-sm font-medium mb-1">Message:</p>
                      <p className="text-sm whitespace-pre-wrap">{campaign.message}</p>
                      <p className="text-xs text-muted-foreground mt-2">
                        Audience: {campaign.target_audience} | Delivered: {campaign.delivered_count}
                      </p>
                    </div>
                  )}
                </CardContent>
              </Card>
            ))
          )}
        </div>
      )}

      {/* === COMPOSE TAB === */}
      {activeTab === 'compose' && (
        <div className="grid gap-6 lg:grid-cols-3">
          {/* Compose Form */}
          <Card className="lg:col-span-2">
            <CardHeader>
              <CardTitle>Compose Campaign</CardTitle>
              <CardDescription>Create and send a bulk SMS campaign</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <label className="text-sm font-medium">Campaign Name</label>
                <input
                  type="text"
                  value={campaignName}
                  onChange={(e) => setCampaignName(e.target.value)}
                  placeholder="e.g. March Delivery Update"
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium">Target Audience</label>
                <select
                  value={targetAudience}
                  onChange={(e) => { setTargetAudience(e.target.value); setRecipientPreview(null); }}
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  <option value="all_customers">All Customers</option>
                  <option value="delivered">Delivered Orders</option>
                  <option value="pending">Pending Orders</option>
                  <option value="returned">Returned Orders</option>
                </select>
                <Button variant="outline" size="sm" onClick={handlePreviewRecipients} disabled={previewLoading}>
                  {previewLoading ? <Loader2 className="mr-2 h-3 w-3 animate-spin" /> : <Users className="mr-2 h-3 w-3" />}
                  Preview Recipients
                </Button>
                {recipientPreview && (
                  <div className="p-3 bg-muted rounded-lg text-sm">
                    <p className="font-medium">{recipientPreview.count} recipients found</p>
                    {recipientPreview.samples.length > 0 && (
                      <p className="text-muted-foreground mt-1">
                        Sample: {recipientPreview.samples.join(', ')}
                      </p>
                    )}
                  </div>
                )}
              </div>

              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <label className="text-sm font-medium">Message</label>
                  <span className="text-xs text-muted-foreground">{charCount} chars | {smsSegments} SMS</span>
                </div>

                {/* Quick template select */}
                {templates.length > 0 && (
                  <select
                    value={selectedTemplate || ''}
                    onChange={(e) => {
                      const id = parseInt(e.target.value);
                      const tpl = templates.find(t => t.id === id);
                      if (tpl) {
                        setComposeMessage(tpl.message);
                        setSelectedTemplate(id);
                      }
                    }}
                    className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm"
                  >
                    <option value="">Load from template...</option>
                    {templates.map(t => (
                      <option key={t.id} value={t.id}>{t.name}</option>
                    ))}
                  </select>
                )}

                <textarea
                  value={composeMessage}
                  onChange={(e) => setComposeMessage(e.target.value)}
                  placeholder="Type your message here. Use variables like {name}, {waybill}..."
                  rows={5}
                  className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring resize-none"
                />
                <div className="flex flex-wrap gap-1">
                  {VARIABLES.map(v => (
                    <button
                      key={v.key}
                      onClick={() => insertVariable(v.key, 'compose')}
                      className="px-2 py-1 text-xs rounded border bg-muted hover:bg-accent transition-colors"
                    >
                      {v.key}
                    </button>
                  ))}
                </div>
              </div>

              {sendResult && (
                <div className={`p-3 rounded-lg text-sm ${sendResult.success ? 'bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-300' : 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300'}`}>
                  {sendResult.message}
                </div>
              )}

              <Button onClick={handleSendCampaign} disabled={isSending || !campaignName.trim() || !composeMessage.trim()} className="w-full">
                {isSending ? (
                  <><Loader2 className="mr-2 h-4 w-4 animate-spin" /> Sending...</>
                ) : (
                  <><Send className="mr-2 h-4 w-4" /> Send Campaign</>
                )}
              </Button>
            </CardContent>
          </Card>

          {/* Quick Send Sidebar */}
          <div className="space-y-4">
            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Quick Send</CardTitle>
                <CardDescription>Send SMS to a single number</CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                <input
                  type="text"
                  value={quickPhone}
                  onChange={(e) => setQuickPhone(e.target.value)}
                  placeholder="09XXXXXXXXX"
                  className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm font-mono focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
                <textarea
                  value={quickMessage}
                  onChange={(e) => setQuickMessage(e.target.value)}
                  placeholder="Message..."
                  rows={3}
                  className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring resize-none"
                />
                <div className="flex flex-wrap gap-1">
                  {VARIABLES.slice(0, 3).map(v => (
                    <button key={v.key} onClick={() => insertVariable(v.key, 'quick')} className="px-1.5 py-0.5 text-xs rounded border bg-muted hover:bg-accent">
                      {v.key}
                    </button>
                  ))}
                </div>
                <Button size="sm" onClick={handleQuickSend} disabled={quickSending || !quickPhone.trim() || !quickMessage.trim()} className="w-full">
                  {quickSending ? <Loader2 className="mr-2 h-3 w-3 animate-spin" /> : <Send className="mr-2 h-3 w-3" />}
                  Send
                </Button>
              </CardContent>
            </Card>

            {/* Message Preview */}
            {composeMessage && (
              <Card>
                <CardHeader>
                  <CardTitle className="text-sm">Preview</CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="rounded-lg bg-green-50 dark:bg-green-950 p-3 text-sm">
                    <p className="whitespace-pre-wrap">{composeMessage}</p>
                  </div>
                  <p className="text-xs text-muted-foreground mt-2">
                    {smsSegments > 1 && <><AlertCircle className="inline h-3 w-3 mr-1" />This will be sent as {smsSegments} SMS segments</>}
                  </p>
                </CardContent>
              </Card>
            )}
          </div>
        </div>
      )}

      {/* === TEMPLATES TAB === */}
      {activeTab === 'templates' && (
        <div className="space-y-4">
          <div className="flex justify-between items-center">
            <p className="text-sm text-muted-foreground">{templates.length} templates</p>
            <Button size="sm" onClick={() => setShowCreateTemplate(!showCreateTemplate)}>
              {showCreateTemplate ? <ChevronUp className="mr-2 h-4 w-4" /> : <Plus className="mr-2 h-4 w-4" />}
              {showCreateTemplate ? 'Cancel' : 'New Template'}
            </Button>
          </div>

          {showCreateTemplate && (
            <Card>
              <CardHeader>
                <CardTitle>Create Template</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="grid gap-3 md:grid-cols-2">
                  <input
                    type="text"
                    value={newTemplateName}
                    onChange={(e) => setNewTemplateName(e.target.value)}
                    placeholder="Template name"
                    className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  />
                  <select
                    value={newTemplateCategory}
                    onChange={(e) => setNewTemplateCategory(e.target.value)}
                    className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm"
                  >
                    <option value="delivery">Delivery</option>
                    <option value="marketing">Marketing</option>
                    <option value="reminder">Reminder</option>
                    <option value="notification">Notification</option>
                  </select>
                </div>
                <textarea
                  value={newTemplateMessage}
                  onChange={(e) => setNewTemplateMessage(e.target.value)}
                  placeholder="Template message with {variables}..."
                  rows={3}
                  className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring resize-none"
                />
                <div className="flex flex-wrap gap-1">
                  {VARIABLES.map(v => (
                    <button key={v.key} onClick={() => insertVariable(v.key, 'template')} className="px-2 py-1 text-xs rounded border bg-muted hover:bg-accent">
                      {v.key}
                    </button>
                  ))}
                </div>
                <Button size="sm" onClick={handleCreateTemplate} disabled={!newTemplateName.trim() || !newTemplateMessage.trim()}>
                  Save Template
                </Button>
              </CardContent>
            </Card>
          )}

          <div className="grid gap-4 md:grid-cols-2">
            {templates.length === 0 ? (
              <Card className="md:col-span-2">
                <CardContent className="flex flex-col items-center justify-center py-12">
                  <FileText className="h-12 w-12 text-muted-foreground/50" />
                  <p className="mt-4 text-muted-foreground">No templates yet</p>
                </CardContent>
              </Card>
            ) : (
              templates.map(tpl => (
                <Card key={tpl.id}>
                  <CardHeader className="pb-2">
                    <div className="flex items-center justify-between">
                      <CardTitle className="text-sm">{tpl.name}</CardTitle>
                      {tpl.category && <Badge variant="outline">{tpl.category}</Badge>}
                    </div>
                  </CardHeader>
                  <CardContent>
                    <p className="text-sm text-muted-foreground whitespace-pre-wrap line-clamp-3">{tpl.message}</p>
                    {tpl.variables.length > 0 && (
                      <div className="flex flex-wrap gap-1 mt-2">
                        {tpl.variables.map(v => (
                          <Badge key={v} variant="secondary" className="text-xs">{v}</Badge>
                        ))}
                      </div>
                    )}
                    <div className="flex gap-2 mt-3">
                      <Button variant="outline" size="sm" onClick={() => { setComposeMessage(tpl.message); setActiveTab('compose'); }}>
                        Use in Campaign
                      </Button>
                      <Button variant="ghost" size="sm" className="text-red-600" onClick={() => handleDeleteTemplate(tpl.id)}>
                        Delete
                      </Button>
                    </div>
                  </CardContent>
                </Card>
              ))
            )}
          </div>
        </div>
      )}

      {/* === LOGS TAB === */}
      {activeTab === 'logs' && (
        <div className="space-y-4">
          {/* Filters */}
          <div className="flex gap-3">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <input
                type="text"
                value={logSearch}
                onChange={(e) => setLogSearch(e.target.value)}
                placeholder="Search phone or message..."
                className="flex h-9 w-full rounded-md border border-input bg-background pl-9 pr-3 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
            </div>
            <select
              value={logStatus}
              onChange={(e) => setLogStatus(e.target.value)}
              className="flex h-9 rounded-md border border-input bg-background px-3 py-1 text-sm"
            >
              <option value="">All Status</option>
              <option value="sent">Sent</option>
              <option value="delivered">Delivered</option>
              <option value="failed">Failed</option>
              <option value="pending">Pending</option>
            </select>
          </div>

          <Card>
            <CardContent className="p-0">
              {filteredLogs.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-12">
                  <Clock className="h-12 w-12 text-muted-foreground/50" />
                  <p className="mt-4 text-muted-foreground">No logs found</p>
                </div>
              ) : (
                <div className="divide-y max-h-[500px] overflow-y-auto">
                  {filteredLogs.map(log => (
                    <div key={log.id} className="flex items-center justify-between p-3 hover:bg-muted/50">
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                          <span className="font-mono text-sm">{log.phone}</span>
                          <Badge variant={
                            log.status === 'sent' || log.status === 'delivered' ? 'success'
                              : log.status === 'failed' ? 'destructive'
                              : 'secondary'
                          } className="text-xs">
                            {log.status}
                          </Badge>
                          {log.campaign_name && (
                            <span className="text-xs text-muted-foreground">{log.campaign_name}</span>
                          )}
                        </div>
                        <p className="text-sm text-muted-foreground truncate mt-0.5">{log.message}</p>
                        {log.error_message && (
                          <p className="text-xs text-red-600 mt-0.5">{log.error_message}</p>
                        )}
                      </div>
                      <span className="text-xs text-muted-foreground whitespace-nowrap ml-4">
                        {log.sent_at ? new Date(log.sent_at).toLocaleString() : new Date(log.created_at).toLocaleString()}
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      )}
    </div>
  );
}
