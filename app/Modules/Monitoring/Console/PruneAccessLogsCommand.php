<?php

namespace App\Modules\Monitoring\Console;

use App\Modules\Attendance\Support\Time;
use App\Modules\Monitoring\Models\AccessLog;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Deletes access_logs rows older than the Aturan setting monitoring.access_log_days (docs/14 2.3). The audit log is kept. */
class PruneAccessLogsCommand extends Command
{
    protected $signature = 'monitoring:prune-access-logs';

    protected $description = 'Delete access log rows older than the retention setting';

    /** Rows per delete, so a first run on a large table does not hold one long lock */
    private const CHUNK = 5000;

    public function handle(Settings $settings): int
    {
        $days = max(1, $settings->int('monitoring.access_log_days'));
        $before = Time::db(CarbonImmutable::now()->subDays($days));
        $deleted = 0;

        do {
            $count = AccessLog::query()->where('created_at', '<', $before)->orderBy('id')->limit(self::CHUNK)->delete();
            $deleted += $count;
        } while ($count === self::CHUNK);

        $this->info("Deleted {$deleted} access log rows older than {$days} days.");

        return self::SUCCESS;
    }
}
