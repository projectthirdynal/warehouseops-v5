<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'color', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean'];
}
