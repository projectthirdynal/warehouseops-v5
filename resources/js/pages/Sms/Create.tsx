import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { ArrowLeft, Send, Eye, Users, Info } from 'lucide-react';
import { format } from 'date-fns';

interface Template {
  id: number;
  name: string;
  message: string;
  category: string | null;
}

interface Props {
  templates: Template[];
  audienceOptions: { value: string; label: string }[];
  variables: Record<string, string>;
}

export default function CreateCampaign({ templates, audienceOptions, variables }: Props) {
  const [preview, setPreview] = useState<{ count: number; sample: any[] } | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);

  const { data, setData, post, processing, errors } = useForm({
    name: '',
    message: '',
    type: 'broadcast',
    target_audience: 'custom',
    filters: {
      status: [] as string[],
      date_from: '',
      date_to: '',
      courier: '',
    },
    scheduled_at: '',
  });

  const fetchPreview = async () => {
    setPreviewLoading(true);
    try {
      const response = await fetch('/sms/preview', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({
          target_audience: data.target_audience,
          filters: data.filters,
        }),
      });
      const result = await response.json();
      setPreview(result);
    } catch (error) {
      console.error('Preview failed:', error);
    } finally {
      setPreviewLoading(false);
    }
  };

  const insertVariable = (variable: string) => {
    setData('message', data.message + variable);
  };

  const applyTemplate = (template: Template) => {
    setData('message', template.message);
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/sms');
  };

  const charCount = data.message.length;
  const smsCount = Math.ceil(charCount / 160) || 1;

  return (
    <AppLayout>
      <Head title="Create SMS Campaign" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center gap-4">
          <Link href="/sms">
            <Button variant="ghost" size="icon">
              <ArrowLeft className="h-5 w-5" />
            </Button>
          </Link>
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Create Campaign</h1>
            <p className="text-muted-foreground">
              Send bulk SMS to your customers
            </p>
          </div>
        </div>

        <form onSubmit={handleSubmit}>
          <div className="grid gap-6 lg:grid-cols-3">
            {/* Main Content */}
            <div className="lg:col-span-2 space-y-6">
              {/* Campaign Details */}
              <Card>
                <CardHeader>
                  <CardTitle>Campaign Details</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="name">Campaign Name</Label>
                    <Input
                      id="name"
                      placeholder="e.g., March Delivery Notification"
                      value={data.name}
                      onChange={(e) => setData('name', e.target.value)}
                    />
                    {errors.name && (
                      <p className="text-sm text-destructive">{errors.name}</p>
                    )}
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="type">Campaign Type</Label>
                    <Select
                      value={data.type}
                      onValueChange={(value) => setData('type', value)}
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="broadcast">Broadcast</SelectItem>
                        <SelectItem value="reminder">Reminder</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </CardContent>
              </Card>

              {/* Message Composition */}
              <Card>
                <CardHeader>
                  <CardTitle>Message</CardTitle>
                  <CardDescription>
                    Compose your SMS message. Use variables for personalization.
                  </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                  {/* Templates */}
                  {templates.length > 0 && (
                    <div className="space-y-2">
                      <Label>Quick Templates</Label>
                      <div className="flex flex-wrap gap-2">
                        {templates.slice(0, 5).map((template) => (
                          <Button
                            key={template.id}
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => applyTemplate(template)}
                          >
                            {template.name}
                          </Button>
                        ))}
                      </div>
                    </div>
                  )}

                  {/* Variables */}
                  <div className="space-y-2">
                    <Label>Insert Variable</Label>
                    <div className="flex flex-wrap gap-2">
                      {Object.entries(variables).map(([variable, description]) => (
                        <Button
                          key={variable}
                          type="button"
                          variant="secondary"
                          size="sm"
                          onClick={() => insertVariable(variable)}
                          title={description}
                        >
                          {variable}
                        </Button>
                      ))}
                    </div>
                  </div>

                  {/* Message Input */}
                  <div className="space-y-2">
                    <Label htmlFor="message">Message Content</Label>
                    <Textarea
                      id="message"
                      placeholder="Hi {name}, your order {waybill} is now {status}. Thank you for choosing us!"
                      value={data.message}
                      onChange={(e) => setData('message', e.target.value)}
                      rows={5}
                      className="font-mono"
                    />
                    {errors.message && (
                      <p className="text-sm text-destructive">{errors.message}</p>
                    )}
                    <div className="flex justify-between text-xs text-muted-foreground">
                      <span>{charCount} characters</span>
                      <span>{smsCount} SMS segment{smsCount > 1 ? 's' : ''}</span>
                    </div>
                  </div>
                </CardContent>
              </Card>

              {/* Audience Selection */}
              <Card>
                <CardHeader>
                  <CardTitle>Audience</CardTitle>
                  <CardDescription>
                    Select who will receive this message
                  </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="space-y-2">
                    <Label>Target Audience</Label>
                    <Select
                      value={data.target_audience}
                      onValueChange={(value) => {
                        setData('target_audience', value);
                        setPreview(null);
                      }}
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {audienceOptions.map((option) => (
                          <SelectItem key={option.value} value={option.value}>
                            {option.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  {data.target_audience === 'custom' && (
                    <div className="space-y-4 p-4 border rounded-lg bg-muted/50">
                      <div className="grid gap-4 md:grid-cols-2">
                        <div className="space-y-2">
                          <Label>Date From</Label>
                          <Input
                            type="date"
                            value={data.filters.date_from}
                            onChange={(e) =>
                              setData('filters', { ...data.filters, date_from: e.target.value })
                            }
                          />
                        </div>
                        <div className="space-y-2">
                          <Label>Date To</Label>
                          <Input
                            type="date"
                            value={data.filters.date_to}
                            onChange={(e) =>
                              setData('filters', { ...data.filters, date_to: e.target.value })
                            }
                          />
                        </div>
                      </div>

                      <div className="space-y-2">
                        <Label>Waybill Status</Label>
                        <div className="flex flex-wrap gap-2">
                          {['PENDING', 'DISPATCHED', 'IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERED', 'RETURNED'].map(
                            (status) => (
                              <Badge
                                key={status}
                                variant={
                                  data.filters.status.includes(status) ? 'default' : 'outline'
                                }
                                className="cursor-pointer"
                                onClick={() => {
                                  const newStatus = data.filters.status.includes(status)
                                    ? data.filters.status.filter((s) => s !== status)
                                    : [...data.filters.status, status];
                                  setData('filters', { ...data.filters, status: newStatus });
                                }}
                              >
                                {status.replace('_', ' ')}
                              </Badge>
                            )
                          )}
                        </div>
                      </div>
                    </div>
                  )}

                  <Button
                    type="button"
                    variant="outline"
                    onClick={fetchPreview}
                    disabled={previewLoading}
                  >
                    <Eye className="mr-2 h-4 w-4" />
                    {previewLoading ? 'Loading...' : 'Preview Recipients'}
                  </Button>

                  {preview && (
                    <div className="p-4 border rounded-lg space-y-3">
                      <div className="flex items-center gap-2">
                        <Users className="h-5 w-5 text-muted-foreground" />
                        <span className="font-medium">
                          {preview.count.toLocaleString()} recipients
                        </span>
                      </div>
                      {preview.sample.length > 0 && (
                        <div className="space-y-2">
                          <p className="text-sm text-muted-foreground">Sample recipients:</p>
                          <div className="space-y-1">
                            {preview.sample.map((recipient, i) => (
                              <div key={i} className="text-sm font-mono">
                                {recipient.phone} - {recipient.name}
                              </div>
                            ))}
                          </div>
                        </div>
                      )}
                    </div>
                  )}
                </CardContent>
              </Card>
            </div>

            {/* Sidebar */}
            <div className="space-y-6">
              {/* Schedule */}
              <Card>
                <CardHeader>
                  <CardTitle>Schedule</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="scheduled_at">Send At (Optional)</Label>
                    <Input
                      id="scheduled_at"
                      type="datetime-local"
                      value={data.scheduled_at}
                      onChange={(e) => setData('scheduled_at', e.target.value)}
                      min={format(new Date(), "yyyy-MM-dd'T'HH:mm")}
                    />
                    <p className="text-xs text-muted-foreground">
                      Leave empty to save as draft
                    </p>
                  </div>
                </CardContent>
              </Card>

              {/* Message Preview */}
              <Card>
                <CardHeader>
                  <CardTitle>Message Preview</CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="p-4 bg-muted rounded-lg">
                    <div className="bg-background p-3 rounded-lg shadow-sm">
                      <p className="text-sm whitespace-pre-wrap">
                        {data.message || 'Your message will appear here...'}
                      </p>
                    </div>
                    <p className="text-xs text-muted-foreground mt-2 text-center">
                      SMS Preview
                    </p>
                  </div>
                </CardContent>
              </Card>

              {/* Cost Estimate */}
              <Card>
                <CardHeader>
                  <CardTitle>Estimate</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Recipients</span>
                    <span className="font-medium">{preview?.count?.toLocaleString() || '-'}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">SMS per recipient</span>
                    <span className="font-medium">{smsCount}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Total SMS</span>
                    <span className="font-medium">
                      {preview ? (preview.count * smsCount).toLocaleString() : '-'}
                    </span>
                  </div>
                </CardContent>
              </Card>

              {/* Actions */}
              <div className="flex flex-col gap-2">
                <Button type="submit" disabled={processing} className="w-full">
                  <Send className="mr-2 h-4 w-4" />
                  {data.scheduled_at ? 'Schedule Campaign' : 'Save as Draft'}
                </Button>
                <Link href="/sms" className="w-full">
                  <Button type="button" variant="outline" className="w-full">
                    Cancel
                  </Button>
                </Link>
              </div>

              {/* Info */}
              <div className="flex items-start gap-2 p-3 bg-blue-50 dark:bg-blue-950 rounded-lg text-sm">
                <Info className="h-4 w-4 text-blue-500 mt-0.5 shrink-0" />
                <p className="text-muted-foreground">
                  After saving, you can review and send the campaign from the campaign details page.
                </p>
              </div>
            </div>
          </div>
        </form>
      </div>
    </AppLayout>
  );
}
