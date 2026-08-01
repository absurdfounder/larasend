<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Domain extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'domain',
        'status',
        'dns_records',
        'verified_at',
        'inbound_enabled_at',
    ];

    protected function casts(): array
    {
        return [
            'dns_records' => 'array',
            'verified_at' => 'datetime',
            'inbound_enabled_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
