import { useState } from 'react';
import { Link } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
  ArrowLeft,
  Download,
  FileSpreadsheet,
  ClipboardList,
  GitMerge,
  Layers,
  Globe,
  ScrollText,
  Package,
} from 'lucide-react';

interface ReviewQueueStats {
  total: number;
  pending: number;
  reviewed: number;
  dismissed: number;
  actioned: number;
}

interface AutoMergeStats {
  total: number;
  pending: number;
  merged: number;
  rejected: number;
  avg_confidence: number;
}

interface FamilyStats {
  total: number;
  active: number;
  merged: number;
  dismissed: number;
  total_members: number;
}

interface CrossPageStats {
  cross_page_order_phones: number;
  cross_page_psids: number;
  cross_page_customers: number;
  affected_pages: number;
  total_pages: number;
}

interface AuditLogStats {
  total: number;
  total_merges: number;
  total_dismissals: number;
}

interface Props {
  reviewQueueStats: ReviewQueueStats;
  autoMergeStats: AutoMergeStats;
  familyStats: FamilyStats;
  crossPageStats: CrossPageStats;
  auditLogStats: AuditLogStats;
}

interface ExportOption {
  key: string;
  title: string;
  description: string;
  icon: typeof FileSpreadsheet;
  iconColor: string;
  stats: { label: string; value: number | string }[];
  url: string;
  filters?: { label: string; key: string; options: string[] }[];
}

export default function DuplicateReviewExport({
  reviewQueueStats,
  autoMergeStats,
  familyStats,
  crossPageStats,
  auditLogStats,
}: Props) {
  const [reviewType, setReviewType] = useState('');
  const [reviewStatus, setReviewStatus] = useState('');
  const [reviewSeverity, setReviewSeverity] = useState('');
  const [autoMergeStatus, setAutoMergeStatus] = useState('');
  const [autoMergeMinConfidence, setAutoMergeMinConfidence] = useState('');
  const [familyStatus, setFamilyStatus] = useState('');
  const [familyMethod, setFamilyMethod] = useState('');

  const downloadCsv = (baseUrl: string, params?: Record<string, string>) => {
    const url = new URL(baseUrl, window.location.origin);
    if (params) {
      Object.entries(params).forEach(([key, value]) => {
        if (value) url.searchParams.set(key, value);
      });
    }
    window.location.href = url.toString();
  };

  const exportOptions: ExportOption[] = [
    {
      key: 'review-queue',
      title: 'Review Queue',
      description:
        'Duplicate review items with type, match method, severity, status, and reviewer details.',
      icon: ClipboardList,
      iconColor: 'text-blue-500',
      stats: [
        { label: 'Total', value: reviewQueueStats.total },
        { label: 'Pending', value: reviewQueueStats.pending },
        { label: 'Reviewed', value: reviewQueueStats.reviewed },
        { label: 'Dismissed', value: reviewQueueStats.dismissed },
      ],
      url: '/api/duplicate-check/export/review-queue',
      filters: [
        { label: 'Type', key: 'type', options: ['', 'order', 'customer', 'conversation'] },
        {
          label: 'Status',
          key: 'status',
          options: ['', 'pending', 'reviewed', 'dismissed', 'actioned'],
        },
        { label: 'Severity', key: 'severity', options: ['', 'low', 'medium', 'high', 'critical'] },
      ],
    },
    {
      key: 'auto-merge',
      title: 'Auto-Merge Suggestions',
      description:
        'Customer merge suggestions with confidence scores, match reasons, and customer details.',
      icon: GitMerge,
      iconColor: 'text-purple-500',
      stats: [
        { label: 'Total', value: autoMergeStats.total },
        { label: 'Pending', value: autoMergeStats.pending },
        { label: 'Merged', value: autoMergeStats.merged },
        { label: 'Avg Confidence', value: `${autoMergeStats.avg_confidence ?? 0}%` },
      ],
      url: '/api/duplicate-check/export/auto-merge',
      filters: [
        { label: 'Status', key: 'status', options: ['', 'pending', 'merged', 'rejected'] },
        { label: 'Min Confidence', key: 'min_confidence', options: ['', '70', '80', '90', '95'] },
      ],
    },
    {
      key: 'families',
      title: 'Duplicate Families',
      description:
        'Family groups with anchor labels, member counts, merge status, and grouping method.',
      icon: Layers,
      iconColor: 'text-green-500',
      stats: [
        { label: 'Total', value: familyStats.total },
        { label: 'Active', value: familyStats.active },
        { label: 'Merged', value: familyStats.merged },
        { label: 'Total Members', value: familyStats.total_members },
      ],
      url: '/api/duplicate-check/export/families',
      filters: [
        { label: 'Status', key: 'status', options: ['', 'active', 'merged', 'dismissed'] },
        { label: 'Method', key: 'method', options: ['', 'phone', 'psid'] },
      ],
    },
    {
      key: 'cross-page',
      title: 'Cross-Page Duplicates',
      description: 'Customers, orders, and conversations spanning multiple Facebook pages.',
      icon: Globe,
      iconColor: 'text-orange-500',
      stats: [
        { label: 'Cross-Page Orders', value: crossPageStats.cross_page_order_phones },
        { label: 'Cross-Page PSIDs', value: crossPageStats.cross_page_psids },
        { label: 'Cross-Page Customers', value: crossPageStats.cross_page_customers },
        {
          label: 'Affected Pages',
          value: `${crossPageStats.affected_pages}/${crossPageStats.total_pages}`,
        },
      ],
      url: '/api/duplicate-check/export/cross-page',
    },
    {
      key: 'audit-log',
      title: 'Audit Logs',
      description:
        'Complete trail of all duplicate-related actions with user, entity, and state changes.',
      icon: ScrollText,
      iconColor: 'text-muted-foreground',
      stats: [
        { label: 'Total Actions', value: auditLogStats.total },
        { label: 'Merges', value: auditLogStats.total_merges },
        { label: 'Dismissals', value: auditLogStats.total_dismissals },
      ],
      url: '/api/duplicate-check/audit-log/export',
    },
  ];

  const filterValues: Record<string, Record<string, string>> = {
    'review-queue': { type: reviewType, status: reviewStatus, severity: reviewSeverity },
    'auto-merge': { status: autoMergeStatus, min_confidence: autoMergeMinConfidence },
    families: { status: familyStatus, method: familyMethod },
    'cross-page': {},
    'audit-log': {},
  };

  const setFilterValue = (exportKey: string, filterKey: string, value: string) => {
    if (exportKey === 'review-queue') {
      if (filterKey === 'type') setReviewType(value);
      if (filterKey === 'status') setReviewStatus(value);
      if (filterKey === 'severity') setReviewSeverity(value);
    } else if (exportKey === 'auto-merge') {
      if (filterKey === 'status') setAutoMergeStatus(value);
      if (filterKey === 'min_confidence') setAutoMergeMinConfidence(value);
    } else if (exportKey === 'families') {
      if (filterKey === 'status') setFamilyStatus(value);
      if (filterKey === 'method') setFamilyMethod(value);
    }
  };

  return (
    <AppLayout>
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="flex items-center gap-2 text-2xl font-bold">
              <Download className="h-7 w-7 text-info" />
              Duplicate Export
            </h1>
            <p className="mt-1 text-sm text-muted-foreground">
              Export duplicate detection data as CSV files for reporting and analysis.
            </p>
          </div>
          <div className="flex gap-2">
            <Link href="/shop/duplicate-review">
              <Button variant="outline" size="sm">
                <ArrowLeft className="mr-1.5 h-4 w-4" />
                Back to Queue
              </Button>
            </Link>
            <Button onClick={() => downloadCsv('/api/duplicate-check/export/all')} size="sm">
              <Package className="mr-1.5 h-4 w-4" />
              Export All (Combined)
            </Button>
          </div>
        </div>

        {/* Combined Export Banner */}
        <Card className="border-info/30 bg-info/5">
          <CardContent className="flex items-center justify-between p-4">
            <div className="flex items-center gap-3">
              <FileSpreadsheet className="h-8 w-8 text-info" />
              <div>
                <p className="font-medium">Combined Duplicate Report</p>
                <p className="text-sm text-muted-foreground">
                  Download a single CSV with all sections: Review Queue, Auto-Merge, Families,
                  Cross-Page, and Audit Logs.
                </p>
              </div>
            </div>
            <Button onClick={() => downloadCsv('/api/duplicate-check/export/all')} size="sm">
              <Download className="mr-1.5 h-4 w-4" />
              Download
            </Button>
          </CardContent>
        </Card>

        {/* Individual Export Cards */}
        <div className="grid gap-4 md:grid-cols-2">
          {exportOptions.map((option) => {
            const Icon = option.icon;
            return (
              <Card key={option.key}>
                <CardContent className="p-4">
                  <div className="flex items-start gap-3">
                    <Icon className={`mt-0.5 h-6 w-6 shrink-0 ${option.iconColor}`} />
                    <div className="min-w-0 flex-1">
                      <p className="font-medium">{option.title}</p>
                      <p className="mt-1 text-sm text-muted-foreground">{option.description}</p>

                      {/* Stats */}
                      <div className="mt-3 flex flex-wrap gap-2">
                        {option.stats.map((stat) => (
                          <Badge key={stat.label} variant="outline" className="text-xs">
                            {stat.label}: {stat.value}
                          </Badge>
                        ))}
                      </div>

                      {/* Filters */}
                      {option.filters && option.filters.length > 0 && (
                        <div className="mt-3 space-y-2">
                          {option.filters.map((filter) => (
                            <div key={filter.key} className="flex items-center gap-2">
                              <span className="text-xs text-muted-foreground">{filter.label}:</span>
                              <select
                                value={filterValues[option.key]?.[filter.key] ?? ''}
                                onChange={(e) =>
                                  setFilterValue(option.key, filter.key, e.target.value)
                                }
                                className="rounded-md border bg-background px-2 py-0.5 text-xs"
                              >
                                {filter.options.map((opt) => (
                                  <option key={opt} value={opt}>
                                    {opt || 'All'}
                                  </option>
                                ))}
                              </select>
                            </div>
                          ))}
                        </div>
                      )}

                      {/* Download Button */}
                      <Button
                        onClick={() => downloadCsv(option.url, filterValues[option.key] ?? {})}
                        size="sm"
                        variant="outline"
                        className="mt-3"
                      >
                        <Download className="mr-1.5 h-4 w-4" />
                        Download CSV
                      </Button>
                    </div>
                  </div>
                </CardContent>
              </Card>
            );
          })}
        </div>
      </div>
    </AppLayout>
  );
}
