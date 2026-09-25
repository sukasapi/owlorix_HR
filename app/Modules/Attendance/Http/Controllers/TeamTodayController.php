<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Services\TeamBoard;
use App\Modules\Attendance\Support\Time;
use App\Modules\Projects\Models\TaskWorkSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tim hari ini (docs/01 4.2.6): who is clocked in, in overtime, quiet, or out right now. The page polls the `board`
 * prop every 30 seconds. Quiet PC detail is shown here because the page is Management only (3.6.4).
 */
class TeamTodayController extends Controller
{
    public function __invoke(Request $request, TeamBoard $board): Response
    {
        $data = $board->for($request->user());
        $data['board']['people'] = $this->withRunningTasks($data['board']['people']);

        return Inertia::render('team-today/Index', [
            'scope' => $data['scope'],
            'board' => $data['board'],
        ]);
    }

    /**
     * Adds the task each person's timer is running on ("sedang mengerjakan"), read from the Projects module. Only the
     * title, the project, and since when; the task page has the rest.
     *
     * @param  list<array<string, mixed>>  $people
     * @return list<array<string, mixed>>
     */
    private function withRunningTasks(array $people): array
    {
        $sessions = TaskWorkSession::query()
            ->whereIn('user_id', array_column($people, 'id'))
            ->whereNull('ended_at')
            ->with(['task' => fn ($q) => $q->select('id', 'title', 'project_id')->with('project:id,name')])
            ->get()
            ->keyBy('user_id');

        return array_map(function (array $person) use ($sessions) {
            $session = $sessions->get($person['id']);

            return [...$person, 'task' => $session?->task === null ? null : [
                'id' => $session->task->id,
                'title' => $session->task->title,
                'project' => $session->task->project?->name,
                'started_at' => Time::iso($session->started_at),
            ]];
        }, $people);
    }
}
