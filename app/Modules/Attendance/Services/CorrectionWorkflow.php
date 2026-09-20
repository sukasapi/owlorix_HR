<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\CorrectionField;
use App\Modules\Attendance\Enums\CorrectionStatus;
use App\Modules\Attendance\Enums\EventType;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\Correction;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Notifications\CorrectionAppliedNotification;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Corrections (3.10): Management proposes for their people, Superadmin applies or declines proposals and can apply a
 * correction directly. Nobody does any of this for their own records. Every step is audited.
 *
 * Applying never edits events: it writes a server event `correction_applied` that the calculator reads as an override,
 * then recalculates the work date, so the shift is still rebuilt from events. A change in overtime minutes sends a
 * decided request back to pending (I12, SyncOvertimeRequest). The person is notified.
 *
 * Refusals: AuthorizationException for who may act, ValidationException keyed `time`, `shift_id`, `reason`, `note`
 * or `correction` for what is wrong with the request.
 */
class CorrectionWorkflow
{
    public function __construct(
        private readonly CorrectionScope $scope,
        private readonly CorrectionPreview $preview,
        private readonly ShiftRecalculator $recalculator,
        private readonly Auditor $auditor,
    ) {}

    public function propose(User $actor, Shift $shift, CorrectionField $field, CarbonImmutable $value, string $reason, ?CarbonImmutable $now = null): Correction
    {
        $now ??= CarbonImmutable::now();
        $person = $this->person($shift);

        if ($actor->is($person)) {
            throw new AuthorizationException('Koreksi untuk catatanmu sendiri harus diajukan orang lain.');
        }

        if (! $this->scope->canPropose($actor, $person)) {
            throw new AuthorizationException('Kamu tidak bisa mengajukan koreksi untuk orang ini.');
        }

        $reason = $this->requireText($reason, 'reason', 'Tulis alasan koreksi.');

        return DB::transaction(function () use ($actor, $shift, $person, $field, $value, $reason, $now) {
            $this->lockPerson($person);
            $outcome = $this->preview->evaluate($shift, $field, $value, $now);

            $correction = Correction::query()->create([
                'shift_id' => $shift->id,
                'field' => $field,
                'old_value' => Time::iso($outcome->oldValue),
                'new_value' => Time::iso($outcome->newValue),
                'reason' => $reason,
                'proposed_by' => $actor->id,
                'status' => CorrectionStatus::Proposed,
            ]);

            $this->auditor->record('correction.proposed', $correction,
                ['field' => $field->value, 'value' => $correction->old_value],
                [
                    'field' => $field->value,
                    'value' => $correction->new_value,
                    'reason' => $reason,
                    'shift_id' => $shift->id,
                    'person_id' => $person->id,
                    'work_date' => $shift->work_date,
                ],
                $actor->id,
            );

            return $correction;
        });
    }

    /** Superadmin corrects a shift without a proposal (3.10.1). */
    public function applyDirect(User $actor, Shift $shift, CorrectionField $field, CarbonImmutable $value, string $reason, ?CarbonImmutable $now = null): Correction
    {
        $now ??= CarbonImmutable::now();
        $person = $this->person($shift);
        $this->authorizeApply($actor, $person);
        $reason = $this->requireText($reason, 'reason', 'Tulis alasan koreksi.');

        return DB::transaction(function () use ($actor, $shift, $person, $field, $value, $reason, $now) {
            $this->lockPerson($person);
            $outcome = $this->preview->evaluate($shift, $field, $value, $now);

            $correction = Correction::query()->create([
                'shift_id' => $shift->id,
                'field' => $field,
                'old_value' => Time::iso($outcome->oldValue),
                'new_value' => Time::iso($outcome->newValue),
                'reason' => $reason,
                'proposed_by' => $actor->id,
                'status' => CorrectionStatus::Proposed,
            ]);

            $this->commit($actor, $correction, $shift, $person, $outcome, $now);

            return $correction;
        });
    }

    /**
     * Superadmin applies a proposal. `$seenValue` is the recorded time the Superadmin was looking at; when the shift
     * changed since, nothing is applied.
     */
    public function apply(User $actor, Correction $correction, ?string $seenValue, ?CarbonImmutable $now = null): Correction
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($actor, $correction, $seenValue, $now) {
            $correction = $this->lockProposal($correction);
            $shift = $correction->shift ?? throw ValidationException::withMessages(['correction' => 'Shift koreksi ini sudah tidak ada.']);
            $person = $this->person($shift);
            $this->authorizeApply($actor, $person);
            $this->lockPerson($person);

            try {
                $outcome = $this->preview->evaluate($shift, $correction->field, $correction->newValue(), $now);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['correction' => collect($e->errors())->flatten()->first()]);
            }

            if (Time::iso($outcome->oldValue) !== $seenValue) {
                $shown = $outcome->oldValue?->setTimezone(Time::zone())->format('H.i');

                throw ValidationException::withMessages(['correction' => $shown !== null
                    ? sprintf('Shift ini berubah: %s sekarang tercatat %s. Periksa lagi sebelum menerapkan.', $correction->field->label(), $shown)
                    : sprintf('Shift ini berubah: %s sekarang tidak tercatat. Periksa lagi sebelum menerapkan.', $correction->field->label())]);
            }

            $this->commit($actor, $correction, $shift, $person, $outcome, $now);

            return $correction;
        });
    }

    /** Superadmin declines a proposal with a note. Nothing about the shift changes. */
    public function decline(User $actor, Correction $correction, string $note, ?CarbonImmutable $now = null): Correction
    {
        $now ??= CarbonImmutable::now();
        $note = $this->requireText($note, 'note', 'Tulis catatan saat menolak koreksi.');

        return DB::transaction(function () use ($actor, $correction, $note, $now) {
            $correction = $this->lockProposal($correction);
            $person = $this->person($correction->shift ?? throw ValidationException::withMessages(['correction' => 'Shift koreksi ini sudah tidak ada.']));
            $this->authorizeApply($actor, $person);

            $correction->update([
                'status' => CorrectionStatus::Declined,
                'approved_by' => $actor->id,
                'decided_at' => $now,
                'decision_note' => $note,
            ]);

            $this->auditor->record('correction.declined', $correction,
                ['status' => CorrectionStatus::Proposed->value],
                ['status' => CorrectionStatus::Declined->value, 'note' => $note, 'shift_id' => $correction->shift_id, 'person_id' => $person->id],
                $actor->id,
            );

            return $correction;
        });
    }

    private function commit(User $actor, Correction $correction, Shift $shift, User $person, CorrectionOutcome $outcome, CarbonImmutable $now): void
    {
        $field = $correction->field;
        $before = $shift->refresh()->only(['clock_in_at', 'clock_out_at', 'regular_minutes', 'overtime_minutes', 'status']);
        $previous = $field === CorrectionField::ClockIn ? $this->preview->neighbour($shift, before: true) : null;

        AttendanceEvent::query()->create([
            'id' => (string) Str::uuid7(),
            'user_id' => $person->id,
            'device_id' => $this->deviceFor($shift),
            'shift_id' => $shift->id,
            'type' => EventType::CorrectionApplied,
            'occurred_at' => $now,
            'occurred_at_device' => $now,
            'boot_id' => 'server',
            'uptime_ms' => 0,
            'server_offset_ms' => 0,
            'offline' => false,
            // shift_id in the payload keeps the event on this shift when a later clock-in relinks events; sequence
            // orders corrections applied within the same millisecond
            'payload' => [
                'field' => $field->value,
                'value' => Time::iso($outcome->newValue),
                'correction_id' => $correction->id,
                'shift_id' => $shift->id,
                'sequence' => AttendanceEvent::query()->where('shift_id', $shift->id)->where('type', EventType::CorrectionApplied->value)->count() + 1,
            ],
            'received_at' => $now,
        ]);

        if ($field === CorrectionField::ClockIn) {
            // Shifts are found and ordered by this column; the recalculation saves the same value from the event
            Shift::query()->whereKey($shift->id)->update(['clock_in_at' => Time::db($outcome->newValue)]);
        }

        $this->recalculator->recalculateWorkDate($person->id, $shift->work_date, $now, keepSavedRegular: false);

        if ($previous !== null && $previous->work_date !== $shift->work_date) {
            $this->recalculator->recalculateWorkDate($person->id, $previous->work_date, $now, keepSavedRegular: false);
        }

        $correction->update([
            'old_value' => Time::iso($outcome->oldValue),
            'status' => CorrectionStatus::Applied,
            'approved_by' => $actor->id,
            'decided_at' => $now,
        ]);

        $after = $shift->refresh()->only(['clock_in_at', 'clock_out_at', 'regular_minutes', 'overtime_minutes', 'status']);

        $this->auditor->record('correction.applied', $correction,
            [
                'field' => $field->value,
                'value' => $correction->old_value,
                'shift' => $this->snapshot($before),
            ],
            [
                'field' => $field->value,
                'value' => $correction->new_value,
                'reason' => $correction->reason,
                'shift_id' => $shift->id,
                'person_id' => $person->id,
                'work_date' => $shift->work_date,
                'proposed_by' => $correction->proposed_by,
                'direct' => $correction->proposed_by === $actor->id,
                'closed_month' => $outcome->closedMonth,
                'shift' => $this->snapshot($after),
            ],
            $actor->id,
        );

        $person->notify(new CorrectionAppliedNotification($correction, $actor, $shift->work_date));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function snapshot(array $values): array
    {
        return [
            'clock_in_at' => Time::iso($values['clock_in_at']),
            'clock_out_at' => Time::iso($values['clock_out_at']),
            'regular_minutes' => $values['regular_minutes'],
            'overtime_minutes' => $values['overtime_minutes'],
            'status' => $values['status']?->value,
        ];
    }

    /** The device the shift was clocked in on. The event table needs a device; `boot_id` 'server' marks who wrote it. */
    private function deviceFor(Shift $shift): string
    {
        $events = AttendanceEvent::query()->where('shift_id', $shift->id);

        $device = (clone $events)->where('type', EventType::ClockIn->value)->value('device_id')
            ?? (clone $events)->orderBy('occurred_at')->value('device_id')
            ?? AttendanceEvent::query()->where('user_id', $shift->user_id)->orderByDesc('occurred_at')->value('device_id');

        if ($device === null) {
            throw ValidationException::withMessages(['shift_id' => 'Shift ini tidak punya catatan perangkat, jadi koreksinya tidak bisa dicatat.']);
        }

        return (string) $device;
    }

    private function authorizeApply(User $actor, User $person): void
    {
        if ($actor->is($person)) {
            throw new AuthorizationException('Koreksi untuk catatanmu sendiri harus diterapkan Superadmin lain.');
        }

        if (! $this->scope->canApply($actor, $person)) {
            throw new AuthorizationException('Hanya Superadmin yang bisa menerapkan atau menolak koreksi.');
        }
    }

    private function lockProposal(Correction $correction): Correction
    {
        $locked = Correction::query()->with('approver:id,name')->lockForUpdate()->findOrFail($correction->id);

        if ($locked->status !== CorrectionStatus::Proposed) {
            throw ValidationException::withMessages(['correction' => sprintf(
                'Koreksi ini sudah %s oleh %s.',
                $locked->status === CorrectionStatus::Applied ? 'diterapkan' : 'ditolak',
                $locked->approver?->name ?? 'orang lain',
            )]);
        }

        return $locked;
    }

    /** One change per person at a time, the same lock the desktop sync takes. */
    private function lockPerson(User $person): void
    {
        User::withTrashed()->whereKey($person->getKey())->lockForUpdate()->first();
    }

    private function person(Shift $shift): User
    {
        return User::withTrashed()->findOrFail($shift->user_id);
    }

    private function requireText(string $text, string $key, string $message): string
    {
        $text = trim($text);

        if ($text === '') {
            throw ValidationException::withMessages([$key => $message]);
        }

        return $text;
    }
}
