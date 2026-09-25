<?php

namespace App\Modules\Monitoring\Console;

use App\Modules\Attendance\Support\Time;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Moves Aktivitas detail rows older than the Aturan setting monitoring.app_usage_active_days into app_usage_archive
 * (owner decision 2026-09-25: archive, never delete). Each chunk is copied and removed in one transaction.
 */
class ArchiveAppUsageCommand extends Command
{
    protected $signature = 'monitoring:archive-app-usage';

    protected $description = 'Move application usage rows older than the active period to the archive table';

    private const CHUNK = 2000;

    private const COLUMNS = ['id', 'client_id', 'user_id', 'shift_id', 'device_id', 'app_name', 'exe', 'window_title', 'is_browser', 'started_at', 'ended_at', 'seconds', 'created_at'];

    public function handle(Settings $settings): int
    {
        $days = max(1, $settings->int('monitoring.app_usage_active_days'));
        $before = Time::db(CarbonImmutable::now()->subDays($days));
        $moved = 0;

        do {
            $count = DB::transaction(function () use ($before) {
                $rows = DB::table('app_usage_sessions')->where('started_at', '<', $before)->orderBy('id')->limit(self::CHUNK)->get(self::COLUMNS);
                if ($rows->isEmpty()) {
                    return 0;
                }
                $now = Time::db(CarbonImmutable::now());
                DB::table('app_usage_archive')->insertOrIgnore($rows->map(fn ($row) => [...(array) $row, 'archived_at' => $now])->all());
                DB::table('app_usage_sessions')->whereIn('id', $rows->pluck('id'))->delete();

                return $rows->count();
            });
            $moved += $count;
        } while ($count === self::CHUNK);

        $this->info("Archived {$moved} application usage rows older than {$days} days.");

        return self::SUCCESS;
    }
}
