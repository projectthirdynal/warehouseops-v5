import { Head } from '@inertiajs/react';
import { ShoppingCart } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';

export default function POSIndex() {
  return (
    <AppLayout>
      <Head title="POS" />

      <div className="space-y-4">
        <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-xl font-bold tracking-tight font-display">Point of Sale</h1>
            <p className="text-muted-foreground">In-store checkout and order processing</p>
          </div>
        </div>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <ShoppingCart className="h-5 w-5 text-primary" />
              POS Workspace
            </CardTitle>
            <CardDescription>
              This module is under development. Features will include product lookup, cart
              management, checkout flow, and receipt generation.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex items-center justify-center py-12 text-muted-foreground">
              <p className="text-sm">POS module — scaffolded and ready for development</p>
            </div>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
