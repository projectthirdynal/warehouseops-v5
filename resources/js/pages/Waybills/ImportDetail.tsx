import { Head, router } from '@inertiajs/react';
import {
  FileSpreadsheet,
  ArrowLeft,
  CheckCircle,
  XCircle,
  Clock,
  Package,
  AlertTriangle,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

interface Waybill {
  id: number;
  waybill_number: string;
  status: string;
  receiver_name: string;
  receiver_phone: string;
  city: string;
  cod_amount: number;
  created_at: string;
}

interface Upload {
  id: number;
  original_filename: string;
  total_rows: number;
  processed_rows: number;
  success_rows: number;
  error_rows: number;
  status: string;
  errors: Array<{ row: number; error: string }> | null;
  uploaded_by: { name: string } | null;
  created_at: string;
}

interface Props {
  upload: Upload;
  waybills: {
    data: Waybill[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
  };
}

export default function ImportDetail({ upload, waybills }: Props) {
  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'DELIVERED':
        return <Badge className="bg-green-100 text-green-800">Delivered</Badge>;
      case 'IN_TRANSIT':
      case 'OUT_FOR_DELIVERY':
        return <Badge className="bg-blue-100 text-blue-800">In Transit</Badge>;
      case 'RETURNED':
        return <Badge className="bg-red-100 text-red-800">Returned</Badge>;
      case 'PENDING':
        return <Badge className="bg-yellow-100 text-yellow-800">Pending</Badge>;
      default:
        return <Badge variant="secondary">{status}</Badge>;
    }
  };

  return (
    <AppLayout>
      <Head title={`Import: ${upload.original_filename}`} />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="icon" onClick={() => router.visit('/waybills/import')}>
            <ArrowLeft className="h-5 w-5" />
          </Button>
          <div className="flex-1">
            <h1 className="text-2xl font-bold tracking-tight flex items-center gap-2">
              <FileSpreadsheet className="h-6 w-6" />
              {upload.original_filename}
            </h1>
            <p className="text-muted-foreground">
              Uploaded {new Date(upload.created_at).toLocaleString()}
              {upload.uploaded_by && ` by ${upload.uploaded_by.name}`}
            </p>
          </div>
        </div>

        {/* Stats Cards */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Total Rows</CardTitle>
              <Package className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{upload.total_rows}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Processed</CardTitle>
              <Clock className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{upload.processed_rows}</div>
              <p className="text-xs text-muted-foreground">
                {upload.total_rows > 0
                  ? Math.round((upload.processed_rows / upload.total_rows) * 100)
                  : 0}% complete
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Success</CardTitle>
              <CheckCircle className="h-4 w-4 text-green-600" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">{upload.success_rows}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Errors</CardTitle>
              <XCircle className="h-4 w-4 text-red-600" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-red-600">{upload.error_rows}</div>
            </CardContent>
          </Card>
        </div>

        {/* Errors */}
        {upload.errors && upload.errors.length > 0 && (
          <Card className="border-red-200">
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-red-600">
                <AlertTriangle className="h-5 w-5" />
                Import Errors
              </CardTitle>
              <CardDescription>
                The following rows had errors during import
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="space-y-2 max-h-60 overflow-y-auto">
                {upload.errors.map((error, index) => (
                  <div
                    key={index}
                    className="flex items-start gap-3 p-2 bg-red-50 rounded-lg text-sm"
                  >
                    <span className="font-mono text-red-600">Row {error.row}</span>
                    <span className="text-red-700">{error.error}</span>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Imported Waybills */}
        <Card>
          <CardHeader>
            <CardTitle>Imported Waybills</CardTitle>
            <CardDescription>
              Waybills created or updated from this import
            </CardDescription>
          </CardHeader>
          <CardContent>
            {waybills.data.length === 0 ? (
              <div className="text-center py-8">
                <Package className="mx-auto h-12 w-12 text-muted-foreground/50" />
                <p className="mt-4 text-muted-foreground">No waybills from this import</p>
              </div>
            ) : (
              <>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b">
                        <th className="text-left py-3 px-2 font-medium">Waybill #</th>
                        <th className="text-left py-3 px-2 font-medium">Receiver</th>
                        <th className="text-left py-3 px-2 font-medium">Phone</th>
                        <th className="text-left py-3 px-2 font-medium">City</th>
                        <th className="text-left py-3 px-2 font-medium">COD</th>
                        <th className="text-left py-3 px-2 font-medium">Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {waybills.data.map((waybill) => (
                        <tr key={waybill.id} className="border-b hover:bg-muted/50">
                          <td className="py-3 px-2">
                            <button
                              onClick={() => router.visit(`/waybills/${waybill.id}`)}
                              className="font-mono text-primary hover:underline"
                            >
                              {waybill.waybill_number}
                            </button>
                          </td>
                          <td className="py-3 px-2">{waybill.receiver_name}</td>
                          <td className="py-3 px-2 font-mono">{waybill.receiver_phone}</td>
                          <td className="py-3 px-2">{waybill.city}</td>
                          <td className="py-3 px-2">
                            {waybill.cod_amount > 0 ? `₱${waybill.cod_amount.toLocaleString()}` : '-'}
                          </td>
                          <td className="py-3 px-2">{getStatusBadge(waybill.status)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                {/* Pagination */}
                {waybills.links.length > 3 && (
                  <div className="flex justify-center gap-1 pt-4">
                    {waybills.links.map((link, index) => (
                      <Button
                        key={index}
                        variant={link.active ? 'default' : 'outline'}
                        size="sm"
                        disabled={!link.url}
                        onClick={() => link.url && router.visit(link.url)}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                      />
                    ))}
                  </div>
                )}
              </>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
