<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReplyTemplateAbVariant extends Model
{
    protected $fillable = [
        'ab_test_id',
        'reply_template_id',
        'variant_label',
        'weight',
        'impressions',
        'uses',
        'conversations_resolved',
    ];

    protected $casts = [
        'impressions' => 'integer',
        'uses' => 'integer',
        'conversations_resolved' => 'integer',
        'weight' => 'integer',
    ];

    public function abTest(): BelongsTo
    {
        return $this->belongsTo(ReplyTemplateAbTest::class, 'ab_test_id');
    }

    public function replyTemplate(): BelongsTo
    {
        return $this->belongsTo(ReplyTemplate::class);
    }

    public function conversionRate(): float
    {
        if ($this->impressions === 0) {
            return 0.0;
        }

        return round(($this->uses / $this->impressions) * 100, 1);
    }

    public function resolutionRate(): float
    {
        if ($this->uses === 0) {
            return 0.0;
        }

        return round(($this->conversations_resolved / $this->uses) * 100, 1);
    }
}
