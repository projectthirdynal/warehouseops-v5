import { Head } from '@inertiajs/react';
import { useState } from 'react';
import {
  ClipboardCheck,
  CheckCircle,
  XCircle,
  Phone,
  MapPin,
  Package,
  User,
  Clock,
  AlertTriangle,
  ChevronRight,
  Volume2,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import type { Lead } from '@/types';

interface QueueItem extends Lead {
  quality_flags?: string[];
  customer_history?: {
    total_orders: number;
    success_rate: number;
    risk_level: string;
  };
}

interface Props {
  queue: QueueItem[];
  stats: {
    pending: number;
    approved_today: number;
    rejected_today: number;
    avg_review_time: string;
  };
}

export default function QCIndex({ queue, stats }: Props) {
  const [currentIndex, setCurrentIndex] = useState(0);
  const [isProcessing, setIsProcessing] = useState(false);

  const currentItem = queue?.[currentIndex];

  const handleApprove = async () => {
    if (!currentItem || isProcessing) return;
    setIsProcessing(true);

    // In production: POST /api/qc/approve/{id}
    await new Promise(resolve => setTimeout(resolve, 500));

    if (currentIndex < queue.length - 1) {
      setCurrentIndex(prev => prev + 1);
    }
    setIsProcessing(false);
  };

  const handleReject = async (_reason: string) => {
    if (!currentItem || isProcessing) return;
    setIsProcessing(true);

    // In production: POST /api/qc/reject/{id}
    await new Promise(resolve => setTimeout(resolve, 500));

    if (currentIndex < queue.length - 1) {
      setCurrentIndex(prev => prev + 1);
    }
    setIsProcessing(false);
  };

  return (
    <AppLayout>
      <Head title="QC Review" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">QC Review</h1>
            <p className="text-muted-foreground">
              Review and approve sales before waybill creation
            </p>
          </div>
          <div className="flex items-center gap-4">
            <Badge variant="outline" className="text-lg px-4 py-2">
              Queue: {stats?.pending || queue?.length || 0}
            </Badge>
          </div>
        </div>

        {/* Stats */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <Clock className="h-4 w-4" /> Pending
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats?.pending || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <CheckCircle className="h-4 w-4 text-green-600" /> Approved Today
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">{stats?.approved_today || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <XCircle className="h-4 w-4 text-red-600" /> Rejected Today
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-red-600">{stats?.rejected_today || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <ClipboardCheck className="h-4 w-4" /> Avg Review Time
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats?.avg_review_time || '0m'}</div>
            </CardContent>
          </Card>
        </div>

        {/* Review Card */}
        {currentItem ? (
          <div className="grid gap-6 lg:grid-cols-3">
            {/* Main Review */}
            <Card className="lg:col-span-2">
              <CardHeader>
                <div className="flex items-center justify-between">
                  <div>
                    <CardTitle className="text-xl">Sale Review</CardTitle>
                    <CardDescription>
                      Item {currentIndex + 1} of {queue.length}
                    </CardDescription>
                  </div>
                  <Badge variant="secondary" className="text-lg">
                    #{currentItem.id}
                  </Badge>
                </div>
              </CardHeader>
              <CardContent className="space-y-6">
                {/* Customer Info */}
                <div className="grid gap-4 md:grid-cols-2">
                  <div className="space-y-3">
                    <h3 className="font-semibold flex items-center gap-2">
                      <User className="h-4 w-4" /> Customer Information
                    </h3>
                    <div className="space-y-2 text-sm">
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Name</span>
                        <span className="font-medium">{currentItem.name}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Phone</span>
                        <span className="font-mono">{currentItem.phone}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Source</span>
                        <Badge variant="outline">{currentItem.source}</Badge>
                      </div>
                    </div>
                  </div>

                  <div className="space-y-3">
                    <h3 className="font-semibold flex items-center gap-2">
                      <MapPin className="h-4 w-4" /> Delivery Address
                    </h3>
                    <p className="text-sm">{currentItem.address}</p>
                    <div className="flex gap-2 text-sm">
                      <Badge variant="outline">{currentItem.city}</Badge>
                      <Badge variant="outline">{currentItem.state}</Badge>
                    </div>
                  </div>
                </div>

                {/* Product Info */}
                <div className="space-y-3">
                  <h3 className="font-semibold flex items-center gap-2">
                    <Package className="h-4 w-4" /> Product Details
                  </h3>
                  <div className="rounded-lg border p-4">
                    <div className="flex items-center justify-between">
                      <div>
                        <div className="font-medium">{currentItem.product_name || 'Product'}</div>
                        <div className="text-sm text-muted-foreground">{currentItem.product_brand}</div>
                      </div>
                      <div className="text-right">
                        <div className="text-2xl font-bold text-green-600">
                          ₱{currentItem.amount?.toLocaleString() || 0}
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                {/* Quality Flags */}
                {currentItem.quality_flags && currentItem.quality_flags.length > 0 && (
                  <div className="rounded-lg border border-yellow-200 bg-yellow-50 p-4 dark:border-yellow-900 dark:bg-yellow-950">
                    <h3 className="font-semibold flex items-center gap-2 text-yellow-800 dark:text-yellow-200">
                      <AlertTriangle className="h-4 w-4" /> Quality Flags
                    </h3>
                    <ul className="mt-2 space-y-1">
                      {currentItem.quality_flags.map((flag, i) => (
                        <li key={i} className="text-sm text-yellow-700 dark:text-yellow-300">
                          • {flag}
                        </li>
                      ))}
                    </ul>
                  </div>
                )}

                {/* Actions */}
                <div className="flex gap-3 pt-4">
                  <Button
                    variant="outline"
                    className="flex-1"
                    onClick={() => handleReject('quality_issue')}
                    disabled={isProcessing}
                  >
                    <XCircle className="mr-2 h-4 w-4" />
                    Reject
                  </Button>
                  <Button className="flex-1" size="lg" onClick={handleApprove} disabled={isProcessing}>
                    <CheckCircle className="mr-2 h-4 w-4" />
                    Approve
                  </Button>
                </div>
              </CardContent>
            </Card>

            {/* Customer History Sidebar */}
            <div className="space-y-4">
              <Card>
                <CardHeader>
                  <CardTitle className="text-sm">Customer History</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                  {currentItem.customer_history ? (
                    <>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Total Orders</span>
                        <span className="font-medium">{currentItem.customer_history.total_orders}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Success Rate</span>
                        <span className="font-medium">{currentItem.customer_history.success_rate}%</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Risk Level</span>
                        <Badge
                          variant={
                            currentItem.customer_history.risk_level === 'LOW'
                              ? 'default'
                              : currentItem.customer_history.risk_level === 'HIGH'
                              ? 'destructive'
                              : 'secondary'
                          }
                        >
                          {currentItem.customer_history.risk_level}
                        </Badge>
                      </div>
                    </>
                  ) : (
                    <p className="text-sm text-muted-foreground">New customer - no history</p>
                  )}
                </CardContent>
              </Card>

              <Card>
                <CardHeader>
                  <CardTitle className="text-sm">Quick Actions</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                  <Button variant="outline" className="w-full justify-start">
                    <Phone className="mr-2 h-4 w-4" />
                    Call Customer
                  </Button>
                  <Button variant="outline" className="w-full justify-start">
                    <Volume2 className="mr-2 h-4 w-4" />
                    Play Recording
                  </Button>
                </CardContent>
              </Card>

              {/* Queue List */}
              <Card>
                <CardHeader>
                  <CardTitle className="text-sm">Queue Preview</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                  {queue.slice(currentIndex, currentIndex + 5).map((item, i) => (
                    <div
                      key={item.id}
                      className={`flex items-center justify-between rounded-lg border p-2 text-sm ${
                        i === 0 ? 'border-primary bg-primary/5' : ''
                      }`}
                    >
                      <div>
                        <div className="font-medium">{item.name}</div>
                        <div className="text-muted-foreground">₱{item.amount?.toLocaleString()}</div>
                      </div>
                      {i === 0 && <ChevronRight className="h-4 w-4 text-primary" />}
                    </div>
                  ))}
                </CardContent>
              </Card>
            </div>
          </div>
        ) : (
          <Card>
            <CardContent className="flex flex-col items-center justify-center py-12">
              <CheckCircle className="h-12 w-12 text-green-600" />
              <h3 className="mt-4 text-lg font-semibold">All Caught Up!</h3>
              <p className="text-muted-foreground">No items pending QC review</p>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
