<?php

namespace App\Events;

use App\Models\Upload;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class WaybillImportEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Upload $upload,
        public array $data = []
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('upload.'.$this->upload->id);
    }
}

class WaybillImportStarted extends WaybillImportEvent
{
    public function broadcastAs(): string
    {
        return 'import.started';
    }
}

class WaybillValidationCompleted extends WaybillImportEvent
{
    public function broadcastAs(): string
    {
        return 'validation.completed';
    }
}

class WaybillChunkProcessed extends WaybillImportEvent
{
    public function __construct(
        Upload $upload,
        public int $chunkNumber,
        public array $counts
    ) {
        parent::__construct($upload, ['chunk_number' => $chunkNumber, 'counts' => $counts]);
    }

    public function broadcastAs(): string
    {
        return 'chunk.processed';
    }
}

class WaybillImportCompleted extends WaybillImportEvent
{
    public function broadcastAs(): string
    {
        return 'import.completed';
    }
}

class WaybillImportFailed extends WaybillImportEvent
{
    public function __construct(
        Upload $upload,
        public string $error
    ) {
        parent::__construct($upload, ['error' => $error]);
    }

    public function broadcastAs(): string
    {
        return 'import.failed';
    }
}
