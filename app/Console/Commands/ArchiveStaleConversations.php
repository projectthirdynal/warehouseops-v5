<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shop\Services\ConversationArchiveService;
use Illuminate\Console\Command;

class ArchiveStaleConversations extends Command
{
    protected $signature = 'shop:archive-stale-conversations';
    protected $description = 'Archive and compress conversations older than configured thresholds';

    public function handle(ConversationArchiveService $service): int
    {
        $settings = $service->getSettings();

        if (! $settings['auto_archive_enabled']) {
            $this->info('Auto-archive disabled. Skipping.');

            return self::SUCCESS;
        }

        $this->info('Archiving stale conversations...');
        $archiveResult = $service->bulkArchive($settings['batch_size']);
        $this->info($archiveResult['message']);

        if ($settings['auto_compress_enabled']) {
            $this->info('Compressing archived conversations...');
            $compressResult = $service->bulkCompress($settings['batch_size']);
            $this->info($compressResult['message']);
            $this->info("Bytes saved: " . number_format($compressResult['total_bytes_saved']));
        }

        return self::SUCCESS;
    }
}
