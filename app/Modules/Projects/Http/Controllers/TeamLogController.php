<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Services\TeamScope;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\WorkActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tim hari ini, tab Log kerja: the work log entries of the people in the viewer's scope, filtered by person, project,
 * and a date range (read only; each person still edits only their own log on Log kerja).
 */
class TeamLogController extends Controller
{
    private const PER_PAGE = 50;

    private const MAX_DAYS = 92;

    public function __invoke(Request $request, TeamScope $scope): Response
    {
        $viewer = $request->user();
        $today = Time::workDate(CarbonImmutable::now());
        $isDate = fn ($value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;

        $to = $isDate($request->query('sampai')) ? min($request->query('sampai'), $today) : $today;
        $from = $isDate($request->query('dari')) ? $request->query('dari') : CarbonImmutable::parse($to)->subDays(6)->toDateString();
        $from = max(min($from, $to), CarbonImmutable::parse($to)->subDays(self::MAX_DAYS)->toDateString());

        $people = $scope->people($viewer)->orderBy('name')->get(['id', 'name', 'username']);
        $personId = filter_var($request->query('orang'), FILTER_VALIDATE_INT) ?: null;
        $person = $personId ? $people->firstWhere('id', $personId) : null;
        $projectId = filter_var($request->query('proyek'), FILTER_VALIDATE_INT) ?: null;

        $start = CarbonImmutable::parse($from, Time::zone())->startOfDay();
        $end = CarbonImmutable::parse($to, Time::zone())->addDay()->startOfDay();

        $query = WorkActivityLog::query()
            ->whereIn('user_id', $person ? [$person->id] : $people->modelKeys())
            ->when($projectId, fn (Builder $q) => $q->where('project_id', $projectId))
            ->where('started_at', '>=', Time::db($start))
            ->where('started_at', '<', Time::db($end));

        $totalSeconds = (int) (clone $query)->sum(DB::raw('TIMESTAMPDIFF(SECOND, started_at, ended_at)'));

        $entries = $query->with(['user:id,name', 'project:id,name,code', 'task:id,title'])
            ->orderByDesc('started_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (WorkActivityLog $log) => [
                'id' => $log->id,
                'person' => $log->user?->name,
                'project' => $log->project ? ['id' => $log->project->id, 'name' => $log->project->name, 'code' => $log->project->code] : null,
                'task' => $log->task ? ['id' => $log->task->id, 'title' => $log->task->title] : null,
                'description' => $log->description,
                // Studio calendar date, so an entry just after midnight UTC shows under the right day
                'date' => Time::workDate(CarbonImmutable::instance($log->started_at)),
                'started_at' => Time::iso(CarbonImmutable::instance($log->started_at)),
                'ended_at' => Time::iso(CarbonImmutable::instance($log->ended_at)),
                'minutes' => (int) round(($log->ended_at->getTimestamp() - $log->started_at->getTimestamp()) / 60),
                'evidence_url' => $log->evidence_url,
            ]);

        return Inertia::render('team-today/Logs', [
            'filters' => ['orang' => $person?->id, 'proyek' => $projectId, 'dari' => $from, 'sampai' => $to],
            'today' => $today,
            'people' => $people->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'username' => $u->username])->all(),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name', 'code'])->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'code' => $p->code])->all(),
            'total_minutes' => intdiv($totalSeconds, 60),
            'entries' => $entries,
        ]);
    }
}
