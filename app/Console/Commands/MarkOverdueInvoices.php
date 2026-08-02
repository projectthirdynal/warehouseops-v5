<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\SupplierInvoice;
use Illuminate\Console\Command;

class MarkOverdueInvoices extends Command
{
    protected $signature = 'invoices:mark-overdue';

    protected $description = 'Mark invoices and supplier invoices past their due date as OVERDUE.';

    public function handle(): int
    {
        $customerCount = Invoice::whereIn('status', ['SENT', 'PARTIAL'])
            ->whereNotNull('date_due')
            ->where('date_due', '<', today())
            ->update(['status' => 'OVERDUE', 'updated_at' => now()]);

        $supplierCount = SupplierInvoice::where('status', 'VALIDATED')
            ->whereNotNull('date_due')
            ->where('date_due', '<', today())
            ->update(['status' => 'OVERDUE', 'updated_at' => now()]);

        $this->info("Marked {$customerCount} customer invoice(s) and {$supplierCount} supplier invoice(s) as OVERDUE.");

        return Command::SUCCESS;
    }
}
