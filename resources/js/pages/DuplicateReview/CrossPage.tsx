import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
  ArrowLeft,
  Layers,
  Search,
  RefreshCw,
  ShoppingBag,
  MessageCircle,
  User,
  Phone,
} from 'lucide-react';
import { formatDateTime } from '@/lib/utils';

interface PageInfo {
  facebook_page_id: number;
  page_name: string;
  order_count?: number;
  conversation_count?: number;
  identity_count?: number;
  first_order_at?: string;
  latest_order_at?: string;
  display_name?: string;
}

interface CrossPageGroup {
  type: 'order' | 'conversation' | 'customer';
  key: string;
  label: string;
  page_count: number;
  order_count?: number;
  conversation_count?: number;
  customer_id?: number;
  customer_phone?: string;
  pages: PageInfo[];
  severity: 'low' | 'medium' | 'high';
}

interface Stats {
  cross_page_order_phones: number;
  cross_page_psids: number;
  cross_page_customers: number;
  affected_pages: number;
  total_pages: number;
  top_pages: {
    facebook_page_id: number;
    page_name: string;
    order_count: number;
    unique_phones: number;
  }[];
}

interface Props {
  groups: CrossPageGroup[];
  totalGroups: number;
  stats: Stats;
}

const typeIcon: Record<string, typeof Layers> = {
  order: ShoppingBag,
  conversation: MessageCircle,
  customer: User,
};

const typeColor: Record<string, string> = {
  order: 'bg-blue-100 text-blue-700',
  conversation: 'bg-purple-100 text-purple-700',
  customer: 'bg-green-100 text-green-700',
};

const severityColor: Record<string, string> = {
  low: 'bg-yellow-100 text-yellow-700',
  medium: 'bg-orange-100 text-orange-700',
  high: 'bg-red-100 text-red-700',
};

export default function DuplicateReviewCrossPage({ groups, totalGroups, stats }: Props) {
  const [typeFilter, setTypeFilter] = useState<string>('');
  const [searchQuery, setSearchQuery] = useState('');
  const [scanning, setScanning] = useState(false);

  const filteredGroups = groups.filter((g) => {
    if (typeFilter && g.type !== typeFilter) return false;
    if (searchQuery && !g.label.toLowerCase().includes(searchQuery.toLowerCase())) return false;
    return true;
  });

  const runScan = () => {
    setScanning(true);
    router.post(
      '/api/duplicate-check/cross-page/scan',
      { limit: 100 },
      {
        preserveScroll: true,
        onSuccess: () => {
          router.reload({ only: ['groups', 'totalGroups', 'stats'] });
          setScanning(false);
        },
        onError: () => setScanning(false),
      }
    );
  };

  return (
    <AppLayout>
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="flex items-center gap-2 text-2xl font-bold">
              <Layers className="h-7 w-7 text-info" />
              Cross-Page Duplicate Detection
            </h1>
            <p className="mt-1 text-sm text-muted-foreground">
              Detect customers, orders, and conversations that span multiple Facebook pages.
            </p>
          </div>
          <div className="flex gap-2">
            <Link href="/shop/duplicate-review">
              <Button variant="outline" size="sm">
                <ArrowLeft className="mr-1.5 h-4 w-4" />
                Back to Queue
              </Button>
            </Link>
            <Button onClick={runScan} size="sm" disabled={scanning}>
              <RefreshCw className={`mr-1.5 h-4 w-4 ${scanning ? 'animate-spin' : ''}`} />
              {scanning ? 'Scanning...' : 'Run Scan'}
            </Button>
          </div>
        </div>

        {/* Stats Cards */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Cross-Page Orders</p>
                <ShoppingBag className="h-4 w-4 text-blue-500" />
              </div>
              <p className="text-2xl font-bold">{stats.cross_page_order_phones}</p>
              <p className="text-xs text-muted-foreground">phones with orders on multiple pages</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Cross-Page PSIDs</p>
                <MessageCircle className="h-4 w-4 text-purple-500" />
              </div>
              <p className="text-2xl font-bold">{stats.cross_page_psids}</p>
              <p className="text-xs text-muted-foreground">PSIDs on multiple pages</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Cross-Page Customers</p>
                <User className="h-4 w-4 text-green-500" />
              </div>
              <p className="text-2xl font-bold">{stats.cross_page_customers}</p>
              <p className="text-xs text-muted-foreground">
                customers with identities on multiple pages
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Affected Pages</p>
                <Layers className="h-4 w-4 text-muted-foreground" />
              </div>
              <p className="text-2xl font-bold">
                {stats.affected_pages}
                <span className="text-base text-muted-foreground"> / {stats.total_pages}</span>
              </p>
              <p className="text-xs text-muted-foreground">pages with cross-page activity</p>
            </CardContent>
          </Card>
        </div>

        {/* Top Pages */}
        {stats.top_pages.length > 0 && (
          <Card>
            <CardContent className="p-4">
              <p className="mb-3 text-sm font-medium">Top Pages by Order Volume</p>
              <div className="space-y-2">
                {stats.top_pages.slice(0, 5).map((page, idx) => (
                  <div
                    key={page.facebook_page_id}
                    className="flex items-center justify-between text-sm"
                  >
                    <span>
                      <span className="mr-2 font-bold text-muted-foreground">#{idx + 1}</span>
                      {page.page_name}
                    </span>
                    <div className="flex gap-3">
                      <Badge variant="outline">{page.order_count} orders</Badge>
                      <Badge variant="outline">{page.unique_phones} phones</Badge>
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Filters */}
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex items-center gap-2">
            <span className="text-sm text-muted-foreground">Type:</span>
            <select
              value={typeFilter}
              onChange={(e) => setTypeFilter(e.target.value)}
              className="rounded-md border bg-background px-3 py-1 text-sm"
            >
              <option value="">All</option>
              <option value="order">Orders</option>
              <option value="conversation">Conversations</option>
              <option value="customer">Customers</option>
            </select>
          </div>
          <div className="flex items-center gap-2">
            <Search className="h-4 w-4 text-muted-foreground" />
            <input
              type="text"
              placeholder="Search by label..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="rounded-md border bg-background px-3 py-1 text-sm"
            />
          </div>
          <span className="text-sm text-muted-foreground">
            {filteredGroups.length} of {totalGroups} groups
          </span>
        </div>

        {/* Cross-Page Groups */}
        {filteredGroups.length > 0 ? (
          <div className="space-y-3">
            {filteredGroups.map((group, idx) => {
              const Icon = typeIcon[group.type] ?? Layers;
              return (
                <Card key={`${group.type}-${group.key}-${idx}`}>
                  <CardContent className="p-4">
                    <div className="flex items-start gap-3">
                      <div
                        className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${
                          typeColor[group.type] ?? 'bg-muted'
                        }`}
                      >
                        <Icon className="h-4 w-4" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                          <Badge className={typeColor[group.type] ?? 'bg-muted'}>
                            {group.type}
                          </Badge>
                          <Badge className={severityColor[group.severity] ?? 'bg-muted'}>
                            {group.severity}
                          </Badge>
                          <span className="truncate text-sm font-medium">{group.label}</span>
                          {group.customer_phone && (
                            <span className="flex items-center gap-1 text-xs text-muted-foreground">
                              <Phone className="h-3 w-3" />
                              {group.customer_phone}
                            </span>
                          )}
                        </div>
                        <div className="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
                          <span>{group.page_count} pages</span>
                          {group.order_count !== undefined && (
                            <span>{group.order_count} orders</span>
                          )}
                          {group.conversation_count !== undefined && (
                            <span>{group.conversation_count} conversations</span>
                          )}
                        </div>

                        {/* Pages breakdown */}
                        <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                          {group.pages.map((page) => (
                            <div
                              key={page.facebook_page_id}
                              className="rounded-md border bg-muted/30 p-2"
                            >
                              <p className="truncate text-xs font-medium">{page.page_name}</p>
                              <div className="mt-1 flex flex-wrap gap-1.5 text-xs text-muted-foreground">
                                {page.order_count !== undefined && (
                                  <span>{page.order_count} orders</span>
                                )}
                                {page.conversation_count !== undefined && (
                                  <span>{page.conversation_count} convos</span>
                                )}
                                {page.identity_count !== undefined && (
                                  <span>{page.identity_count} identities</span>
                                )}
                              </div>
                              {page.first_order_at && (
                                <p className="mt-1 text-xs text-muted-foreground">
                                  First: {formatDateTime(page.first_order_at)}
                                </p>
                              )}
                              {page.latest_order_at && (
                                <p className="text-xs text-muted-foreground">
                                  Latest: {formatDateTime(page.latest_order_at)}
                                </p>
                              )}
                              {page.display_name && (
                                <p className="mt-1 truncate text-xs text-muted-foreground">
                                  Name: {page.display_name}
                                </p>
                              )}
                            </div>
                          ))}
                        </div>
                      </div>
                    </div>
                  </CardContent>
                </Card>
              );
            })}
          </div>
        ) : (
          <Card>
            <CardContent className="flex flex-col items-center justify-center py-12">
              <Layers className="mb-3 h-12 w-12 text-muted-foreground" />
              <p className="text-sm text-muted-foreground">
                No cross-page duplicates found. Run a scan to detect customers active across
                multiple Facebook pages.
              </p>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
