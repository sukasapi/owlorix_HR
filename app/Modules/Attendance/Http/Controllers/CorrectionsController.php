<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Enums\CorrectionField;
use App\Modules\Attendance\Http\Requests\CorrectionRequest;
use App\Modules\Attendance\Models\Correction;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\CorrectionPage;
use App\Modules\Attendance\Services\CorrectionPreview;
use App\Modules\Attendance\Services\CorrectionScope;
use App\Modules\Attendance\Services\CorrectionWorkflow;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Koreksi (3.10): the list of corrections, the shifts of a person on a date for the form, a preview of the minutes a
 * correction gives, and the form itself. A Superadmin's form applies at once; Management's form sends a proposal.
 */
class CorrectionsController extends Controller
{
    public function index(Request $request, CorrectionPage $page): Response
    {
        $status = $request->query('status');
        $person = $request->query('orang');

        return Inertia::render('corrections/Index', $page->build($request->user(), [
            'status' => is_string($status) && in_array($status, CorrectionPage::STATUSES, true) ? $status : 'all',
            'person' => is_string($person) && ctype_digit($person) ? (int) $person : null,
        ], CarbonImmutable::now()));
    }

    public function shifts(Request $request, CorrectionPage $page, CorrectionScope $scope): JsonResponse
    {
        $data = $request->validate([
            'person_id' => ['required', 'integer'],
            'work_date' => ['required', 'date_format:Y-m-d'],
        ], [
            'person_id.required' => __('attendance::messages.pick_person'),
            'work_date.*' => __('attendance::messages.pick_work_date'),
        ]);

        $person = User::withTrashed()->find($data['person_id']);

        if ($person === null || ! $scope->canCorrect($request->user(), $person)) {
            return response()->json(['message' => __('attendance::messages.cannot_correct_person')], 403);
        }

        return response()->json($page->shifts($person, $data['work_date'], CarbonImmutable::now()));
    }

    public function preview(CorrectionRequest $form, CorrectionPreview $preview, CorrectionScope $scope): JsonResponse
    {
        $shift = $this->shiftInScope($form, $scope);

        if ($shift === null) {
            return response()->json(['message' => __('attendance::messages.cannot_correct_shift')], 403);
        }

        return response()->json($preview->evaluate($shift, $form->correctionField(), $form->value())->toArray());
    }

    public function store(CorrectionRequest $form, CorrectionWorkflow $workflow, CorrectionScope $scope): RedirectResponse
    {
        $actor = $form->user();
        $shift = Shift::query()->find($form->validated('shift_id'))
            ?? throw ValidationException::withMessages(['shift_id' => __('attendance::messages.shift_gone')]);
        $person = User::withTrashed()->findOrFail($shift->user_id);
        $field = $form->correctionField();

        try {
            if ($scope->canApply($actor, $person)) {
                $correction = $workflow->applyDirect($actor, $shift, $field, $form->value(), (string) $form->validated('reason'));

                return back()->with('status', $this->appliedMessage($correction, $person));
            }

            $workflow->propose($actor, $shift, $field, $form->value(), (string) $form->validated('reason'));
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages(['correction' => $e->getMessage()]);
        }

        return back()->with('status', "Koreksi untuk {$person->name} dikirim ke Superadmin.");
    }

    public function apply(Request $request, Correction $correction, CorrectionWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['seen_value' => ['nullable', 'string', 'max:40']]);

        try {
            $applied = $workflow->apply($request->user(), $correction, $data['seen_value'] ?? null);
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages(['correction' => $e->getMessage()]);
        }

        return back()->with('status', $this->appliedMessage($applied, User::withTrashed()->findOrFail($applied->shift->user_id)));
    }

    public function decline(Request $request, Correction $correction, CorrectionWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']], ['note.max' => __('attendance::messages.note_max')]);

        try {
            $declined = $workflow->decline($request->user(), $correction, (string) ($data['note'] ?? ''));
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages(['correction' => $e->getMessage()]);
        }

        $name = $declined->shift?->user?->name ?? '';

        return back()->with('status', __('attendance::messages.declined', ['name' => $name]));
    }

    /** Refuses shifts of people outside the viewer's scope, and the viewer's own. */
    private function shiftInScope(CorrectionRequest $form, CorrectionScope $scope): ?Shift
    {
        $shift = Shift::query()->find($form->validated('shift_id'));
        $person = $shift !== null ? User::withTrashed()->find($shift->user_id) : null;

        return $person !== null && $scope->canCorrect($form->user(), $person) ? $shift : null;
    }

    private function appliedMessage(Correction $correction, User $person): string
    {
        $date = CarbonImmutable::parse((string) $correction->shift?->work_date)->locale('id')->translatedFormat('l, j F');
        $time = $correction->newValue()->setTimezone(Time::zone())->format('H.i');
        $label = $correction->field instanceof CorrectionField ? $correction->field->label() : '';

        return __('attendance::messages.applied', [
            'field' => $label,
            'name' => $person->name,
            'date' => $date,
            'time' => $time,
        ]);
    }
}
