import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  Search,
  Filter,
  Download,
  Upload,
  MoreHorizontal,
  Eye,
  Truck,
  Package,
  CheckCircle,
  XCircle,
  Clock,
  ArrowUpDown,
  Trash2,
} from 'lucide-react';
import { Checkbox } from '@/components/ui/checkbox';
import { BulkActionBar, type BulkAction } from '@/components/BulkActionBar';
import { InlineEdit } from '@/components/InlineEdit';
import { RowExpand } from '@/components/RowExpand';
import { useToast } from '@/hooks/use-toast';
import { useSavedFilters } from '@/hooks/use-saved-filters';
import { SkeletonTable } from '@/components/ui/skeleton';
import { DetailDrawer } from '@/components/DetailDrawer';
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
import type { Waybill, PaginatedResponse, WaybillStatus } from '@/types';
import { formatDate } from '@/lib/utils';

interface Props {
  waybills: PaginatedResponse<Waybill>;
  filters: {
    status?: string;
    search?: string;
    courier?: string;
  };
  stats: {
    total: number;
    pending: number;
    dispatched: number;
    delivered: number;
    returned: number;
  };
}

const statusConfig: Record<WaybillStatus, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline'; icon: typeof Package }> = {
  PENDING: { label: 'Pending', variant: 'secondary', icon: Clock },
  DISPATCHED: { label: 'Dispatched', variant: 'default', icon: Truck },
  PICKED_UP: { label: 'Picked Up', variant: 'default', icon: Package },
  IN_TRANSIT: { label: 'In Transit', variant: 'default', icon: Truck },
  OUT_FOR_DELIVERY: { label: 'Out for Delivery', variant: 'default', icon: Truck },
  AT_WAREHOUSE: { label: 'At Warehouse', variant: 'outline', icon: Package },
  DELIVERED: { label: 'Delivered', variant: 'default', icon: CheckCircle },
  RETURNED: { label: 'Returned', variant: 'destructive', icon: XCircle },
  CANCELLED: { label: 'Cancelled', variant: 'destructive', icon: XCircle },
};

export default function WaybillsIndex({ waybills, filters, stats }: Props) {
  const [search, setSearch] = useState(filters?.search || '');
  const [statusFilter, setStatusFilter] = useState(filters?.status || 'all');
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [loading, setLoading] = useState(false);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerWaybill, setDrawerWaybill] = useState<Waybill | null>(null);
  const { success, error } = useToast();
  const { apply: applyFilters } = useSavedFilters('/waybills');

  const waybillIds = waybills?.data?.map((w) => String(w.id)) || [];

  const toggleSelection = (id: string) => {
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
    );
  };

  const selectAll = (checked: boolean) => {
    setSelectedIds(checked ? waybillIds : []);
  };

  const clearSelection = () => setSelectedIds([]);

  const handleDeleteBulk = (ids: string[]) => {
    if (!confirm(`Delete ${ids.length} waybill(s)? This cannot be undone.`)) return;
    setLoading(true);
    router.delete('/waybills/bulk', {
      data: { ids },
      onSuccess: () => {
        success(`${ids.length} waybills deleted`);
        clearSelection();
        setLoading(false);
      },
      onError: () => {
        error('Failed to delete waybills');
        setLoading(false);
      },
    });
  };

  const handleExportBulk = (ids: string[]) => {
    success(`Exporting ${ids.length} waybills...`);
    window.open(`/waybills/export?ids=${ids.join(',')}`, '_blank');
    clearSelection();
  };

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    applyFilters({ search, status: statusFilter !== 'all' ? statusFilter : undefined });
  };

  const handleStatusFilter = (value: string) => {
    setStatusFilter(value);
    setLoading(true);
    applyFilters({ search, status: value !== 'all' ? value : undefined });
    setTimeout(() => setLoading(false), 300);
  };

  const bulkActions: BulkAction[] = [
    { id: 'delete', label: 'Delete', icon: Trash2, variant: 'destructive', onClick: handleDeleteBulk },
    { id: 'export', label: 'Export', icon: Download, variant: 'default', onClick: handleExportBulk },
  ];

  return (
    <AppLayout>
      <Head title="Waybills" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Waybills</h1>
            <p className="text-muted-foreground">
              Manage and track all shipment waybills
            </p>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" size="sm">
              <Download className="mr-2 h-4 w-4" />
              Export
            </Button>
            <Button variant="outline" size="sm">
              <Upload className="mr-2 h-4 w-4" />
              Import
            </Button>
          </div>
        </div>

        {/* Stats Cards */}
        <div className="grid gap-4 md:grid-cols-5">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Total</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats?.total || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Pending</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-yellow-600">{stats?.pending || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Dispatched</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-blue-600">{stats?.dispatched || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Delivered</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">{stats?.delivered || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Returned</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-red-600">{stats?.returned || 0}</div>
            </CardContent>
          </Card>
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="pt-6">
            <form onSubmit={handleSearch} className="flex flex-col gap-4 md:flex-row">
              <div className="relative flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <input
                  type="text"
                  placeholder="Search by waybill number, name, or phone..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  className="flex h-10 w-full rounded-md border border-input bg-background pl-10 pr-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
              </div>
              <Select value={statusFilter} onValueChange={handleStatusFilter}>
                <SelectTrigger className="w-full md:w-[180px]">
                  <SelectValue placeholder="Status" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Status</SelectItem>
                  <SelectItem value="PENDING">Pending</SelectItem>
                  <SelectItem value="DISPATCHED">Dispatched</SelectItem>
                  <SelectItem value="IN_TRANSIT">In Transit</SelectItem>
                  <SelectItem value="DELIVERED">Delivered</SelectItem>
                  <SelectItem value="RETURNED">Returned</SelectItem>
                </SelectContent>
              </Select>
              <Button type="submit">
                <Filter className="mr-2 h-4 w-4" />
                Filter
              </Button>
            </form>
          </CardContent>
        </Card>

        {/* Bulk Actions */}
        <BulkActionBar
          selectedIds={selectedIds}
          totalCount={waybillIds.length}
          onSelectAll={selectAll}
          onClear={clearSelection}
          actions={bulkActions}
        />

        {/* Table */}
        <Card>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              {loading ? (
                <SkeletonTable rows={5} columns={8} />
              ) : (
              <table className="w-full">
                <thead>
                  <tr className="border-b bg-muted/50">
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground w-10">
                      <Checkbox
                        checked={selectedIds.length === waybillIds.length && waybillIds.length > 0}
                        onCheckedChange={(c) => selectAll(Boolean(c))}
                        aria-label="Select all"
                      />
                    </th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                      <button className="flex items-center gap-1 hover:text-foreground">
                        Waybill # <ArrowUpDown className="h-4 w-4" />
                      </button>
                    </th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Receiver</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Address</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Status</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Remarks</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">COD</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Date</th>
                    <th className="h-12 px-4 text-right align-middle font-medium text-muted-foreground">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {waybills?.data?.length > 0 ? (
                    waybills.data.map((waybill) => {
                      const config = statusConfig[waybill.status];
                      const StatusIcon = config?.icon || Package;
                      return (
                        <>
                        <tr key={waybill.id} className="border-b transition-colors hover:bg-muted/50">
                          <td className="p-4 align-middle w-10">
                            <Checkbox
                              checked={selectedIds.includes(String(waybill.id))}
                              onCheckedChange={() => toggleSelection(String(waybill.id))}
                              aria-label={`Select waybill ${waybill.waybill_number}`}
                            />
                          </td>
                          <td className="p-4 align-middle font-mono text-sm font-medium">
                            {waybill.waybill_number}
                          </td>
                          <td className="p-4 align-middle">
                            <div>
                              <div className="font-medium">{waybill.receiver_name}</div>
                              <div className="text-sm text-muted-foreground">{waybill.receiver_phone}</div>
                            </div>
                          </td>
                          <td className="p-4 align-middle max-w-[200px]">
                            <div className="truncate text-sm">{waybill.receiver_address}</div>
                            <div className="text-xs text-muted-foreground">
                              {[waybill.barangay, waybill.city, waybill.state].filter(Boolean).join(', ')}
                            </div>
                          </td>
                          <td className="p-4 align-middle">
                            <Badge variant={config?.variant || 'secondary'} className="gap-1">
                              <StatusIcon className="h-3 w-3" />
                              {config?.label || waybill.status}
                            </Badge>
                          </td>
                          <td className="p-4 align-middle text-sm max-w-[200px]">
                            <InlineEdit
                              value={waybill.remarks || ''}
                              onSave={async (val) => {
                                router.patch(`/waybills/${waybill.id}/remarks`, { remarks: val }, {
                                  preserveState: true,
                                  onSuccess: () => success('Remarks updated'),
                                  onError: () => error('Failed to update remarks'),
                                });
                              }}
                              placeholder="Add remarks..."
                            />
                          </td>
                          <td className="p-4 align-middle text-sm font-medium">
                            ₱{waybill.cod_amount?.toLocaleString() || '0'}
                          </td>
                          <td className="p-4 align-middle text-sm text-muted-foreground">
                            {formatDate(waybill.created_at)}
                          </td>
                          <td className="p-4 align-middle text-right">
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon">
                                  <MoreHorizontal className="h-4 w-4" />
                                </Button>
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end">
                                <DropdownMenuItem onClick={() => { setDrawerWaybill(waybill); setDrawerOpen(true); }}>
                                  <Eye className="mr-2 h-4 w-4" />
                                  View Details
                                </DropdownMenuItem>
                                <DropdownMenuItem>
                                  <Truck className="mr-2 h-4 w-4" />
                                  Update Status
                                </DropdownMenuItem>
                              </DropdownMenuContent>
                            </DropdownMenu>
                          </td>
                        </tr>
                        {/* Expanded row */}
                        {selectedIds.includes(String(waybill.id)) && (
                          <tr>
                            <td colSpan={9} className="px-4 pb-3">
                              <RowExpand defaultOpen>
                                <div className="grid grid-cols-3 gap-4 text-sm">
                                  <div>
                                    <p className="text-muted-foreground text-xs uppercase">Phone</p>
                                    <p className="font-medium">{waybill.receiver_phone ?? '—'}</p>
                                  </div>
                                  <div>
                                    <p className="text-muted-foreground text-xs uppercase">Address</p>
                                    <p className="font-medium">{[waybill.barangay, waybill.city, waybill.state].filter(Boolean).join(', ') || '—'}</p>
                                  </div>
                                  <div>
                                    <p className="text-muted-foreground text-xs uppercase">COD Amount</p>
                                    <p className="font-medium">₱{waybill.cod_amount?.toLocaleString() || '0'}</p>
                                  </div>
                                  <div>
                                    <p className="text-muted-foreground text-xs uppercase">Created</p>
                                    <p className="font-medium">{formatDate(waybill.created_at)}</p>
                                  </div>
                                  <div>
                                    <p className="text-muted-foreground text-xs uppercase">Dispatched</p>
                                    <p className="font-medium">{waybill.dispatched_at ? formatDate(waybill.dispatched_at) : '—'}</p>
                                  </div>
                                  <div>
                                    <p className="text-muted-foreground text-xs uppercase">Delivered</p>
                                    <p className="font-medium">{waybill.delivered_at ? formatDate(waybill.delivered_at) : '—'}</p>
                                  </div>
                                </div>
                              </RowExpand>
                            </td>
                          </tr>
                        )}
                        </>
                      );
                    })
                  ) : (
                    <tr>
                      <td colSpan={9} className="h-24 text-center text-muted-foreground">
                        No waybills found
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
              )}
            </div>

            {/* Pagination */}
            {waybills?.last_page > 1 && (
              <div className="flex items-center justify-between border-t px-4 py-4">
                <div className="text-sm text-muted-foreground">
                  Showing {waybills.from} to {waybills.to} of {waybills.total} results
                </div>
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={waybills.current_page === 1}
                    onClick={() => router.get('/waybills', { ...filters, page: waybills.current_page - 1 })}
                  >
                    Previous
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={waybills.current_page === waybills.last_page}
                    onClick={() => router.get('/waybills', { ...filters, page: waybills.current_page + 1 })}
                  >
                    Next
                  </Button>
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Detail Drawer */}
      <DetailDrawer
        open={drawerOpen}
        onClose={() => { setDrawerOpen(false); setDrawerWaybill(null); }}
        title={drawerWaybill ? `Waybill #${drawerWaybill.waybill_number}` : 'Waybill Details'}
      >
        {drawerWaybill && (
          <div className="space-y-6">
            <div className="flex items-center gap-2">
              <Badge variant={statusConfig[drawerWaybill.status]?.variant || 'secondary'}>
                {statusConfig[drawerWaybill.status]?.label || drawerWaybill.status}
              </Badge>
              <span className="text-sm text-muted-foreground">
                {formatDate(drawerWaybill.created_at)}
              </span>
            </div>
            <div className="space-y-4">
              <div>
                <p className="text-xs text-muted-foreground uppercase">Receiver</p>
                <p className="font-medium">{drawerWaybill.receiver_name}</p>
                <p className="text-sm text-muted-foreground">{drawerWaybill.receiver_phone}</p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground uppercase">Address</p>
                <p className="font-medium">{drawerWaybill.receiver_address}</p>
                <p className="text-sm text-muted-foreground">
                  {[drawerWaybill.barangay, drawerWaybill.city, drawerWaybill.state].filter(Boolean).join(', ')}
                </p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground uppercase">Remarks</p>
                <InlineEdit
                  value={drawerWaybill.remarks || ''}
                  onSave={async (val) => {
                    router.patch(`/waybills/${drawerWaybill.id}/remarks`, { remarks: val }, {
                      preserveState: true,
                      onSuccess: () => success('Remarks updated'),
                      onError: () => error('Failed to update remarks'),
                    });
                  }}
                  placeholder="Add remarks..."
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <p className="text-xs text-muted-foreground uppercase">COD Amount</p>
                  <p className="font-medium">₱{drawerWaybill.cod_amount?.toLocaleString() || '0'}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground uppercase">Status</p>
                  <p className="font-medium">{drawerWaybill.status}</p>
                </div>
              </div>
            </div>
            <div className="flex gap-2 pt-4 border-t">
              <Button variant="outline" size="sm" onClick={() => router.visit(`/waybills/${drawerWaybill.id}`)}>
                Open Full Page
              </Button>
              <Button size="sm" onClick={() => { success('Status update dialog would open here'); }}>
                Update Status
              </Button>
            </div>
          </div>
        )}
      </DetailDrawer>
    </AppLayout>
  );
}
