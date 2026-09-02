<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageStatusLabel extends Model
{
    protected $table = 'page_status_labels';

    protected $fillable = [
        'facebook_page_id',
        'status',
        'label',
        'color',
    ];

    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class);
    }
}
