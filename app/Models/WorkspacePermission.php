<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspacePermission extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'can_list' => 'boolean',
            'can_read' => 'boolean',
            'can_write' => 'boolean',
            'can_delete' => 'boolean',
            'can_execute' => 'boolean',
            'can_admin' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
