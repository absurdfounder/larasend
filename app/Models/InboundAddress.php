<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A specific inbound address routed to a project.
 *
 * On the shared Trooper platform domain many organizations receive mail
 * through ONE Cloudflare zone (and therefore one router source). Exact-address
 * rows are what keep each agent's mail inside its own org project.
 */
class InboundAddress extends Model
{
    protected $fillable = [
        'project_id',
        'address',
        'label',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
