<?php

namespace App\Modules\Shared\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class Auditor
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, ?int $actorId = null): AuditLog
    {
        return AuditLog::query()->create([
            'actor_id' => $actorId ?? $this->request->user()?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => $this->request->ip(),
            'created_at' => now(),
        ]);
    }
}
