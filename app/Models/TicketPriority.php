<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketPriority extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'color', 'level', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean'];
}
