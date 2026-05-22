<?php

declare(strict_types=1);

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\QboConnection;
use App\Domain\Finance\Models\QboSyncQueue;
use App\Domain\Finance\Services\QboClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class QboSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(public readonly int $queueRowId) {}

    public function backoff(): array
    {
        return [60, 300, 900]; // 1m, 5m, 15m
    }

    public function handle(): void
    {
        $row = QboSyncQueue::find($this->queueRowId);
        if (! $row || $row->status === 'SYNCED') return;

        // If no active connection, skip silently — user hasn't connected QBO yet.
        if (! QboConnection::active()) {
            Log::info('QboSyncJob skipped: no active connection', ['row' => $row->id]);
            return;
        }

        $row->attempts++;
        $row->save();

        try {
            $client = new QboClient();

            // Skip if already synced (defensive — idempotency_key on QBO side also prevents duplicates)
            if ($row->qbo_id) {
                $row->status   = 'SYNCED';
                $row->synced_at = now();
                $row->save();
                return;
            }

            $entity   = $this->resolveEntity($row->entity_type);
            $response = $client->create($entity, (array) $row->payload, $row->idempotency_key);

            $row->qbo_id   = $this->extractQboId($response, $entity);
            $row->status    = 'SYNCED';
            $row->synced_at = now();
            $row->save();

        } catch (Throwable $e) {
            $row->error_message = substr($e->getMessage(), 0, 65535);
            $row->status        = $row->attempts >= $this->tries ? 'FAILED' : 'PENDING';
            $row->save();

            if ($row->attempts < $this->tries) {
                $this->release($this->backoff()[$row->attempts - 1] ?? 900);
            } else {
                Log::error('QboSyncJob exhausted retries', ['row' => $row->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function resolveEntity(string $entityType): string
    {
        return match ($entityType) {
            'bill'              => 'bill',
            'journal_entry'     => 'journalentry',
            'deposit'           => 'deposit',
            'expense'           => 'purchase',
            'vendor'            => 'vendor',
            'item'              => 'item',
            'purchase_order'    => 'purchaseorder',
            'fixed_asset'       => 'fixedassetschedule',
            'inventory_adjust'  => 'inventoryadjustment',
            default             => $entityType,
        };
    }

    private function extractQboId(array $response, string $entity): ?string
    {
        $key = ucfirst($entity);
        $key = match ($key) {
            'Journalentry'        => 'JournalEntry',
            'Purchaseorder'       => 'PurchaseOrder',
            'Inventoryadjustment' => 'InventoryAdjustment',
            'Fixedassetschedule'  => 'FixedAssetSchedule',
            default               => $key,
        };
        return $response[$key]['Id'] ?? null;
    }
}
