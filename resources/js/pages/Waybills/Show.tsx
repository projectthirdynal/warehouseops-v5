import { Head, Link } from '@inertiajs/react';
import { useEffect, useRef, useState, useCallback } from 'react';
import axios from 'axios';
import QRCode from 'qrcode';
import { toast } from 'sonner';
import {
  ArrowLeft,
  Package,
  User,
  Phone,
  MapPin,
  Truck,
  Clock,
  CheckCircle,
  XCircle,
  Star,
  TrendingUp,
  AlertTriangle,
  Calendar,
  DollarSign,
  QrCode as QrCodeIcon,
  Printer,
  Download,
  Camera,
  FileText,
  PenTool,
  Upload,
  Trash2,
  Loader2,
  ImageIcon,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { formatDate, formatDateTime } from '@/lib/utils';

interface Waybill {
  id: number;
  waybill_number: string;
  status: string;
  receiver_name: string;
  receiver_phone: string;
  receiver_address: string;
  city: string;
  state: string;
  barangay?: string;
  cod_amount?: number;
  shipping_cost?: number;
  remarks?: string;
  rts_reason?: string;
  item_name?: string;
  item_qty?: number;
  courier_provider: string;
  creator_code?: string;
  express_type?: string;
  sender_name?: string;
  sender_phone?: string;
  created_at: string;
  submitted_at?: string;
  dispatched_at?: string;
  delivered_at?: string;
  returned_at?: string;
  uploaded_by?: { name: string };
}

interface OrderHistoryItem {
  id: number;
  waybill_number: string;
  status: string;
  cod_amount?: number;
  remarks?: string;
  created_at: string;
  delivered_at?: string;
  returned_at?: string;
}

interface Customer {
  id: number;
  phone: string;
  name: string;
  total_orders: number;
  successful_orders: number;
  returned_orders: number;
  success_rate: number;
  risk_level: string;
  is_blacklisted: boolean;
}

interface CustomerStats {
  total_orders: number;
  delivered: number;
  returned: number;
  pending: number;
  total_cod: number;
  success_rate: number;
}

interface CustomerRating {
  score: number;
  label: string;
  color: string;
}

interface DeliveryProofItem {
  id: number;
  waybill_id: number;
  type: 'photo' | 'signature' | 'pod_document' | 'other';
  file_path: string;
  original_filename: string | null;
  mime_type: string | null;
  file_size: number | null;
  source: 'courier_callback' | 'manual_upload' | 'courier_api';
  courier_code: string | null;
  metadata: Record<string, unknown> | null;
  uploaded_by: { name: string } | null;
  uploader: { name: string } | null;
  created_at: string;
  url: string;
}

interface Props {
  waybill: Waybill;
  customer: Customer | null;
  orderHistory: OrderHistoryItem[];
  customerStats: CustomerStats;
  customerRating: CustomerRating;
  deliveryProofs?: DeliveryProofItem[];
}

const statusConfig: Record<
  string,
  {
    label: string;
    variant: 'default' | 'secondary' | 'destructive' | 'outline';
    icon: typeof Package;
  }
> = {
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

const ratingColors: Record<string, string> = {
  green: 'bg-success/10 text-success border-success/20',
  blue: 'bg-info/10 text-info border-info/20',
  yellow: 'bg-warning/10 text-warning border-warning/20',
  orange: 'bg-warning/10 text-warning border-warning/20',
  red: 'bg-destructive/10 text-destructive border-destructive/20',
};

export default function WaybillShow({
  waybill,
  customer,
  orderHistory,
  customerStats,
  customerRating,
  deliveryProofs = [],
}: Props) {
  const config = statusConfig[waybill.status] || statusConfig.PENDING;
  const StatusIcon = config.icon;

  const [proofs, setProofs] = useState<DeliveryProofItem[]>(deliveryProofs);
  const [uploading, setUploading] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [lightboxUrl, setLightboxUrl] = useState<string | null>(null);

  const qrCanvasRef = useRef<HTMLCanvasElement>(null);
  const [qrDataUrl, setQrDataUrl] = useState<string | null>(null);
  const [qrLoading, setQrLoading] = useState(false);

  const generateQrCode = useCallback(async () => {
    setQrLoading(true);
    try {
      const { data } = await axios.get(`/waybills/${waybill.id}/qr-code`);
      if (qrCanvasRef.current) {
        await QRCode.toCanvas(qrCanvasRef.current, data.qr_content, {
          width: 200,
          margin: 2,
          color: { dark: '#000000', light: '#ffffff' },
          errorCorrectionLevel: 'M',
        });
        const url = qrCanvasRef.current.toDataURL('image/png');
        setQrDataUrl(url);
      }
    } catch {
      toast.error('Failed to generate QR code.');
    } finally {
      setQrLoading(false);
    }
  }, [waybill.id]);

  useEffect(() => {
    generateQrCode();
  }, [generateQrCode]);

  function downloadQrCode() {
    if (!qrDataUrl) return;
    const link = document.createElement('a');
    link.href = qrDataUrl;
    link.download = `qr-${waybill.waybill_number}.png`;
    link.click();
  }

  function printLabel() {
    window.open(`/waybills/${waybill.id}/qr-code/label`, '_blank', 'width=500,height=700');
  }

  function handleUpload(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;

    setUploading(true);
    const formData = new FormData();
    formData.append('file', file);
    formData.append('type', 'photo');

    axios
      .post(`/waybills/${waybill.id}/delivery-proofs`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      .then(({ data }) => {
        if (data.success) {
          setProofs((prev) => [data.proof, ...prev]);
          toast.success('Delivery proof uploaded.');
        } else {
          toast.error(data.message || 'Upload failed.');
        }
      })
      .catch(() => toast.error('Failed to upload delivery proof.'))
      .finally(() => {
        setUploading(false);
        if (fileInputRef.current) fileInputRef.current.value = '';
      });
  }

  function handleDelete(proofId: number) {
    setDeletingId(proofId);
    axios
      .delete(`/waybills/${waybill.id}/delivery-proofs/${proofId}`)
      .then(() => {
        setProofs((prev) => prev.filter((p) => p.id !== proofId));
        toast.success('Delivery proof deleted.');
      })
      .catch(() => toast.error('Failed to delete delivery proof.'))
      .finally(() => setDeletingId(null));
  }

  const photos = proofs.filter((p) => p.type === 'photo');
  const signatures = proofs.filter((p) => p.type === 'signature');
  const documents = proofs.filter((p) => p.type === 'pod_document' || p.type === 'other');

  const typeIcon = (type: string) => {
    if (type === 'signature') return PenTool;
    if (type === 'pod_document' || type === 'other') return FileText;
    return Camera;
  };

  return (
    <AppLayout>
      <Head title={`Waybill ${waybill.waybill_number}`} />

      <div className="space-y-4">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Link href="/waybills">
              <Button variant="outline" size="icon">
                <ArrowLeft className="h-4 w-4" />
              </Button>
            </Link>
            <div>
              <h1 className="text-xl font-bold font-display tracking-tight font-mono">
                {waybill.waybill_number}
              </h1>
              <p className="text-muted-foreground">
                {waybill.submitted_at
                  ? `Submitted ${formatDate(waybill.submitted_at)}`
                  : `Added ${formatDate(waybill.created_at)}`}
              </p>
            </div>
          </div>
          <Badge variant={config.variant} className="gap-1 text-sm px-3 py-1">
            <StatusIcon className="h-4 w-4" />
            {config.label}
          </Badge>
        </div>

        <div className="grid gap-6 lg:grid-cols-3">
          {/* Main Content - 2 columns */}
          <div className="lg:col-span-2 space-y-6">
            {/* Waybill Details */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Package className="h-5 w-5" />
                  Shipment Details
                </CardTitle>
              </CardHeader>
              <CardContent className="grid gap-4 md:grid-cols-2">
                <div className="space-y-3">
                  <div>
                    <p className="text-sm text-muted-foreground">Receiver</p>
                    <p className="font-medium flex items-center gap-2">
                      <User className="h-4 w-4 text-muted-foreground" />
                      {waybill.receiver_name}
                    </p>
                  </div>
                  <div>
                    <p className="text-sm text-muted-foreground">Phone</p>
                    <p className="font-medium flex items-center gap-2">
                      <Phone className="h-4 w-4 text-muted-foreground" />
                      {waybill.receiver_phone}
                    </p>
                  </div>
                  <div>
                    <p className="text-sm text-muted-foreground">Address</p>
                    <p className="font-medium flex items-start gap-2">
                      <MapPin className="h-4 w-4 text-muted-foreground mt-0.5" />
                      <span>
                        {waybill.receiver_address}
                        <br />
                        <span className="text-sm text-muted-foreground">
                          {[waybill.barangay, waybill.city, waybill.state]
                            .filter(Boolean)
                            .join(', ')}
                        </span>
                      </span>
                    </p>
                  </div>
                </div>
                <div className="space-y-3">
                  <div>
                    <p className="text-sm text-muted-foreground">Item</p>
                    <p className="font-medium">{waybill.item_name || '-'}</p>
                  </div>
                  <div>
                    <p className="text-sm text-muted-foreground">Remarks</p>
                    <p className="font-medium">{waybill.remarks || '-'}</p>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <p className="text-sm text-muted-foreground">COD Amount</p>
                      <p className="font-medium text-lg">
                        ₱{waybill.cod_amount?.toLocaleString() || '0'}
                      </p>
                    </div>
                    <div>
                      <p className="text-sm text-muted-foreground">Shipping</p>
                      <p className="font-medium">
                        ₱{waybill.shipping_cost?.toLocaleString() || '0'}
                      </p>
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Order History */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Clock className="h-5 w-5" />
                  Customer Order History
                  <Badge variant="secondary" className="ml-2">
                    {orderHistory.length} orders
                  </Badge>
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="overflow-x-auto">
                  <table className="w-full">
                    <thead>
                      <tr className="border-b bg-muted/50">
                        <th className="h-10 px-3 text-left text-sm font-medium text-muted-foreground">
                          Waybill #
                        </th>
                        <th className="h-10 px-3 text-left text-sm font-medium text-muted-foreground">
                          Status
                        </th>
                        <th className="h-10 px-3 text-left text-sm font-medium text-muted-foreground">
                          Remarks
                        </th>
                        <th className="h-10 px-3 text-left text-sm font-medium text-muted-foreground">
                          COD
                        </th>
                        <th className="h-10 px-3 text-left text-sm font-medium text-muted-foreground">
                          Date
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {orderHistory.map((order) => {
                        const orderConfig = statusConfig[order.status] || statusConfig.PENDING;
                        const isCurrentOrder = order.id === waybill.id;
                        return (
                          <tr
                            key={order.id}
                            className={`border-b transition-colors ${isCurrentOrder ? 'bg-info/5' : 'hover:bg-muted/50'}`}
                          >
                            <td className="p-3 font-mono text-sm">
                              {isCurrentOrder ? (
                                <span className="font-bold">{order.waybill_number}</span>
                              ) : (
                                <Link
                                  href={`/waybills/${order.id}`}
                                  className="text-info hover:underline"
                                >
                                  {order.waybill_number}
                                </Link>
                              )}
                              {isCurrentOrder && (
                                <Badge variant="outline" className="ml-2 text-xs">
                                  Current
                                </Badge>
                              )}
                            </td>
                            <td className="p-3">
                              <Badge variant={orderConfig.variant} className="text-xs">
                                {orderConfig.label}
                              </Badge>
                            </td>
                            <td
                              className="p-3 text-sm max-w-[200px] truncate"
                              title={order.remarks || ''}
                            >
                              {order.remarks || '-'}
                            </td>
                            <td className="p-3 text-sm font-medium">
                              ₱{order.cod_amount?.toLocaleString() || '0'}
                            </td>
                            <td className="p-3 text-sm text-muted-foreground">
                              {formatDate(order.created_at)}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </CardContent>
            </Card>

            {/* Delivery Proofs */}
            <Card>
              <CardHeader>
                <div className="flex items-center justify-between">
                  <div>
                    <CardTitle className="flex items-center gap-2">
                      <Camera className="h-5 w-5" />
                      Delivery Proofs
                    </CardTitle>
                    <CardDescription className="mt-1">
                      Photos, signatures, and POD documents from courier callbacks or manual uploads
                    </CardDescription>
                  </div>
                  <div className="flex items-center gap-2">
                    <input
                      ref={fileInputRef}
                      type="file"
                      accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
                      onChange={handleUpload}
                      className="hidden"
                    />
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => fileInputRef.current?.click()}
                      disabled={uploading}
                    >
                      {uploading ? (
                        <>
                          <Loader2 className="h-4 w-4 mr-1 animate-spin" />
                          Uploading...
                        </>
                      ) : (
                        <>
                          <Upload className="h-4 w-4 mr-1" />
                          Upload Proof
                        </>
                      )}
                    </Button>
                  </div>
                </div>
              </CardHeader>
              <CardContent>
                {proofs.length === 0 ? (
                  <div className="flex flex-col items-center justify-center py-12 text-center">
                    <ImageIcon className="h-10 w-10 text-muted-foreground/50 mb-3" />
                    <p className="text-sm text-muted-foreground">
                      No delivery proofs yet. Upload manually or they will appear here when the
                      courier sends callback data.
                    </p>
                  </div>
                ) : (
                  <div className="space-y-6">
                    {/* Photos */}
                    {photos.length > 0 && (
                      <div>
                        <h4 className="text-sm font-medium text-muted-foreground mb-3 flex items-center gap-1.5">
                          <Camera className="h-4 w-4" />
                          Photos ({photos.length})
                        </h4>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                          {photos.map((proof) => {
                            const isImage = proof.mime_type?.startsWith('image/');
                            return (
                              <div
                                key={proof.id}
                                className="group relative rounded-lg border overflow-hidden bg-muted"
                              >
                                {isImage ? (
                                  <img
                                    src={proof.url}
                                    alt={proof.original_filename ?? 'Delivery photo'}
                                    className="w-full h-32 object-cover cursor-pointer"
                                    onClick={() => setLightboxUrl(proof.url)}
                                  />
                                ) : (
                                  <div className="w-full h-32 flex items-center justify-center">
                                    <FileText className="h-8 w-8 text-muted-foreground" />
                                  </div>
                                )}
                                <div className="p-2">
                                  <p className="text-xs text-muted-foreground truncate">
                                    {proof.source === 'courier_callback' ? (
                                      <Badge variant="secondary" className="text-xs mr-1">
                                        Courier
                                      </Badge>
                                    ) : (
                                      <Badge variant="outline" className="text-xs mr-1">
                                        Manual
                                      </Badge>
                                    )}
                                    {formatDate(proof.created_at)}
                                  </p>
                                </div>
                                <button
                                  onClick={() => handleDelete(proof.id)}
                                  disabled={deletingId === proof.id}
                                  className="absolute top-1 right-1 rounded-full bg-destructive/90 p-1 opacity-0 group-hover:opacity-100 transition-opacity"
                                >
                                  {deletingId === proof.id ? (
                                    <Loader2 className="h-3 w-3 text-white animate-spin" />
                                  ) : (
                                    <Trash2 className="h-3 w-3 text-white" />
                                  )}
                                </button>
                              </div>
                            );
                          })}
                        </div>
                      </div>
                    )}

                    {/* Signatures */}
                    {signatures.length > 0 && (
                      <div>
                        <h4 className="text-sm font-medium text-muted-foreground mb-3 flex items-center gap-1.5">
                          <PenTool className="h-4 w-4" />
                          Signatures ({signatures.length})
                        </h4>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                          {signatures.map((proof) => (
                            <div
                              key={proof.id}
                              className="group relative rounded-lg border overflow-hidden bg-muted"
                            >
                              <img
                                src={proof.url}
                                alt={proof.original_filename ?? 'Signature'}
                                className="w-full h-32 object-cover cursor-pointer"
                                onClick={() => setLightboxUrl(proof.url)}
                              />
                              <div className="p-2">
                                <p className="text-xs text-muted-foreground truncate">
                                  {proof.source === 'courier_callback' ? (
                                    <Badge variant="secondary" className="text-xs mr-1">
                                      Courier
                                    </Badge>
                                  ) : (
                                    <Badge variant="outline" className="text-xs mr-1">
                                      Manual
                                    </Badge>
                                  )}
                                  {formatDate(proof.created_at)}
                                </p>
                              </div>
                              <button
                                onClick={() => handleDelete(proof.id)}
                                disabled={deletingId === proof.id}
                                className="absolute top-1 right-1 rounded-full bg-destructive/90 p-1 opacity-0 group-hover:opacity-100 transition-opacity"
                              >
                                {deletingId === proof.id ? (
                                  <Loader2 className="h-3 w-3 text-white animate-spin" />
                                ) : (
                                  <Trash2 className="h-3 w-3 text-white" />
                                )}
                              </button>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                    {/* Documents */}
                    {documents.length > 0 && (
                      <div>
                        <h4 className="text-sm font-medium text-muted-foreground mb-3 flex items-center gap-1.5">
                          <FileText className="h-4 w-4" />
                          Documents ({documents.length})
                        </h4>
                        <div className="space-y-2">
                          {documents.map((proof) => {
                            const Icon = typeIcon(proof.type);
                            return (
                              <div
                                key={proof.id}
                                className="group flex items-center gap-3 rounded-lg border p-3 hover:bg-muted/50"
                              >
                                <Icon className="h-5 w-5 text-muted-foreground flex-shrink-0" />
                                <div className="flex-1 min-w-0">
                                  <a
                                    href={proof.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-sm font-medium hover:underline truncate block"
                                  >
                                    {proof.original_filename ?? 'Document'}
                                  </a>
                                  <p className="text-xs text-muted-foreground">
                                    {proof.source === 'courier_callback' ? (
                                      <Badge variant="secondary" className="text-xs mr-1">
                                        Courier
                                      </Badge>
                                    ) : (
                                      <Badge variant="outline" className="text-xs mr-1">
                                        Manual
                                      </Badge>
                                    )}
                                    {proof.uploader?.name ?? 'Unknown'} ·{' '}
                                    {formatDate(proof.created_at)}
                                  </p>
                                </div>
                                <button
                                  onClick={() => handleDelete(proof.id)}
                                  disabled={deletingId === proof.id}
                                  className="text-muted-foreground hover:text-destructive transition-colors"
                                >
                                  {deletingId === proof.id ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                  ) : (
                                    <Trash2 className="h-4 w-4" />
                                  )}
                                </button>
                              </div>
                            );
                          })}
                        </div>
                      </div>
                    )}
                  </div>
                )}
              </CardContent>
            </Card>
          </div>

          {/* Sidebar - Customer Info */}
          <div className="space-y-6">
            {/* Customer Rating Card */}
            <Card className={`border-2 ${ratingColors[customerRating.color]}`}>
              <CardHeader className="pb-2">
                <CardTitle className="flex items-center justify-between">
                  <span className="flex items-center gap-2">
                    <Star className="h-5 w-5" />
                    Customer Rating
                  </span>
                  <span className="text-xl font-bold font-display">{customerRating.score}/5</span>
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="flex items-center justify-between">
                  <span className="text-lg font-semibold">{customerRating.label}</span>
                  <div className="flex gap-1">
                    {[1, 2, 3, 4, 5].map((star) => (
                      <Star
                        key={star}
                        className={`h-5 w-5 ${star <= customerRating.score ? 'fill-current' : 'opacity-30'}`}
                      />
                    ))}
                  </div>
                </div>
                <p className="text-sm mt-2 opacity-80">
                  Based on {customerStats.success_rate}% success rate
                </p>
              </CardContent>
            </Card>

            {/* Customer Stats */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <TrendingUp className="h-5 w-5" />
                  Customer Statistics
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div className="text-center p-3 bg-muted rounded-lg">
                    <p className="text-xl font-bold font-display">{customerStats.total_orders}</p>
                    <p className="text-xs text-muted-foreground">Total Orders</p>
                  </div>
                  <div className="text-center p-3 bg-success/5 rounded-lg">
                    <p className="text-xl font-bold font-display text-success">
                      {customerStats.delivered}
                    </p>
                    <p className="text-xs text-muted-foreground">Delivered</p>
                  </div>
                  <div className="text-center p-3 bg-destructive/5 rounded-lg">
                    <p className="text-xl font-bold font-display text-destructive">
                      {customerStats.returned}
                    </p>
                    <p className="text-xs text-muted-foreground">Returned</p>
                  </div>
                  <div className="text-center p-3 bg-warning/5 rounded-lg">
                    <p className="text-xl font-bold font-display text-warning">
                      {customerStats.pending}
                    </p>
                    <p className="text-xs text-muted-foreground">Pending</p>
                  </div>
                </div>

                <div className="pt-4 border-t">
                  <div className="flex justify-between items-center mb-2">
                    <span className="text-sm text-muted-foreground">Success Rate</span>
                    <span className="font-bold text-lg">{customerStats.success_rate}%</span>
                  </div>
                  <div className="w-full bg-muted rounded-full h-2">
                    <div
                      className={`h-2 rounded-full ${
                        customerStats.success_rate >= 75
                          ? 'bg-success/50'
                          : customerStats.success_rate >= 50
                            ? 'bg-warning/50'
                            : 'bg-destructive/50'
                      }`}
                      style={{ width: `${customerStats.success_rate}%` }}
                    />
                  </div>
                </div>

                <div className="pt-4 border-t">
                  <div className="flex justify-between items-center">
                    <span className="text-sm text-muted-foreground flex items-center gap-1">
                      <DollarSign className="h-4 w-4" />
                      Total COD Value
                    </span>
                    <span className="font-bold text-lg">
                      ₱{customerStats.total_cod.toLocaleString()}
                    </span>
                  </div>
                </div>

                {customer?.is_blacklisted && (
                  <div className="pt-4 border-t">
                    <div className="flex items-center gap-2 text-destructive bg-destructive/5 p-3 rounded-lg">
                      <AlertTriangle className="h-5 w-5" />
                      <span className="font-medium">Customer is Blacklisted</span>
                    </div>
                  </div>
                )}
              </CardContent>
            </Card>

            {/* Shipment Timeline */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Calendar className="h-5 w-5" />
                  Timeline
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="space-y-3">
                  {waybill.submitted_at && (
                    <div className="flex items-start gap-3">
                      <div className="w-2 h-2 mt-2 rounded-full bg-info/50" />
                      <div>
                        <p className="font-medium text-sm">Submitted to Courier</p>
                        <p className="text-xs text-muted-foreground">
                          {formatDateTime(waybill.submitted_at)}
                        </p>
                      </div>
                    </div>
                  )}
                  {waybill.dispatched_at && (
                    <div className="flex items-start gap-3">
                      <div className="w-2 h-2 mt-2 rounded-full bg-warning" />
                      <div>
                        <p className="font-medium text-sm">Dispatched</p>
                        <p className="text-xs text-muted-foreground">
                          {formatDateTime(waybill.dispatched_at)}
                        </p>
                      </div>
                    </div>
                  )}
                  {waybill.delivered_at && (
                    <div className="flex items-start gap-3">
                      <div className="w-2 h-2 mt-2 rounded-full bg-success/50" />
                      <div>
                        <p className="font-medium text-sm">Delivered</p>
                        <p className="text-xs text-muted-foreground">
                          {formatDateTime(waybill.delivered_at)}
                        </p>
                      </div>
                    </div>
                  )}
                  {waybill.returned_at && (
                    <div className="flex items-start gap-3">
                      <div className="w-2 h-2 mt-2 rounded-full bg-destructive/50" />
                      <div>
                        <p className="font-medium text-sm">Returned</p>
                        <p className="text-xs text-muted-foreground">
                          {formatDateTime(waybill.returned_at)}
                        </p>
                        {waybill.rts_reason && (
                          <p className="text-xs text-destructive mt-1">{waybill.rts_reason}</p>
                        )}
                      </div>
                    </div>
                  )}
                </div>
              </CardContent>
            </Card>

            {/* QR Code */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <QrCodeIcon className="h-5 w-5" />
                  QR Code
                </CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col items-center gap-3">
                {qrLoading ? (
                  <div className="h-[200px] w-[200px] flex items-center justify-center">
                    <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
                  </div>
                ) : (
                  <canvas ref={qrCanvasRef} className="rounded-lg border" />
                )}
                <p className="text-xs text-muted-foreground text-center font-mono break-all">
                  {waybill.waybill_number}
                </p>
                <div className="flex gap-2 w-full">
                  <Button
                    variant="outline"
                    size="sm"
                    className="flex-1"
                    onClick={downloadQrCode}
                    disabled={!qrDataUrl}
                  >
                    <Download className="h-4 w-4 mr-1" />
                    PNG
                  </Button>
                  <Button variant="outline" size="sm" className="flex-1" onClick={printLabel}>
                    <Printer className="h-4 w-4 mr-1" />
                    Label
                  </Button>
                </div>
              </CardContent>
            </Card>
          </div>
        </div>
      </div>

      {/* Lightbox */}
      {lightboxUrl && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
          onClick={() => setLightboxUrl(null)}
        >
          <img
            src={lightboxUrl}
            alt="Delivery proof"
            className="max-h-[90vh] max-w-[90vw] object-contain rounded-lg"
          />
          <button
            className="absolute top-4 right-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20"
            onClick={(e) => {
              e.stopPropagation();
              setLightboxUrl(null);
            }}
          >
            <XCircle className="h-6 w-6" />
          </button>
        </div>
      )}
    </AppLayout>
  );
}
