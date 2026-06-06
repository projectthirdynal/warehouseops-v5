<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    protected $fillable = ['role', 'permission_id'];

    public $timestamps = true;

    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }
}
