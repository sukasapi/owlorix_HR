<?php

namespace App\Modules\Projects\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\LinkCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A link from the project page to a folder or file that stays on Google Drive or another service (docs/16). */
class ProjectLink extends Model
{
    protected $fillable = [
        'project_id',
        'category',
        'label',
        'url',
        'note',
        'managers_only',
        'position',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => LinkCategory::class,
            'managers_only' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The label typed by the manager, or the category name when they left it empty. */
    public function title(): string
    {
        return $this->label ?? $this->category->label();
    }

    public function host(): ?string
    {
        $host = parse_url($this->url, PHP_URL_HOST);

        return is_string($host) ? preg_replace('/^www\./', '', strtolower($host)) : null;
    }

    /**
     * The service the link opens, so people know what they are about to open before they leave the app. Null for
     * any other site; the page then shows the host name.
     */
    public function service(): ?string
    {
        $host = $this->host();
        $path = (string) parse_url($this->url, PHP_URL_PATH);

        return match (true) {
            $host === 'drive.google.com' && str_contains($path, '/folders/') => 'drive_folder',
            $host === 'drive.google.com' => 'drive',
            $host === 'docs.google.com' && str_starts_with($path, '/document/') => 'docs',
            $host === 'docs.google.com' && str_starts_with($path, '/spreadsheets/') => 'sheets',
            $host === 'docs.google.com' && str_starts_with($path, '/presentation/') => 'slides',
            $host === 'docs.google.com' && str_starts_with($path, '/forms/') => 'forms',
            $host === 'figma.com' => 'figma',
            in_array($host, ['youtube.com', 'm.youtube.com', 'youtu.be'], true) => 'youtube',
            $host === 'vimeo.com' => 'vimeo',
            default => null,
        };
    }
}
