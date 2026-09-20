<?php

namespace App\Modules\Attendance\Calculation;

use App\Modules\Attendance\Enums\EndReason;
use App\Modules\Attendance\Enums\EventType;
use App\Modules\Attendance\Enums\IdleTag;
use App\Modules\Attendance\Enums\OvertimeEndReason;
use App\Modules\Attendance\Enums\ShiftFlag;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Support\Time;
use Closure;

/**
 * One pass over a shift's events in occurred order. Between two events the time rules run
 * (8-hour mark, prompt timeout, presence check, resume window), so the result for a given moment
 * is the same whether it is computed then or hours later. All times are UTC milliseconds.
 *
 * @internal used by ShiftCalculator
 */
final class ShiftWalk
{
    private const MINUTE = 60_000;

    private const REGULAR = 'regular';

    private const PROMPTED = 'prompted';

    private const OVERTIME = 'overtime';

    private const ENDED = 'ended';

    private readonly ShiftRules $rules;

    /** The recorded clock-in while the events are walked; a corrected one afterwards (applyCorrections) */
    private int $clockIn;

    private string $phase = self::REGULAR;

    private int $cursor;

    private int $endMs;

    /** Regular time worked so far, interruptions excluded */
    private int $activeMs = 0;

    /** Regular time left on this work date; null on a non-workday */
    private ?int $remainingMs = null;

    /** The 8-hour mark once reached */
    private ?int $mark = null;

    private ?int $promptDeadline = null;

    /** "Keep working" arrived before the server's mark (the PC counted slightly ahead) */
    private bool $overtimeChosenEarly = false;

    private ?int $overtimeStart = null;

    private ?int $overtimeEnd = null;

    private ?int $clockOut = null;

    private ?EndReason $endReason = null;

    private ?OvertimeEndReason $overtimeEndReason = null;

    private bool $needsReview = false;

    private ?int $interruptedSince = null;

    /** @var array{type: EventType, device: string}|null the event that started the running interruption */
    private ?array $interruptionCause = null;

    /** The running interruption is a crash gap the PC already decided to resume from; no resume timeout applies */
    private bool $interruptionUnchecked = false;

    private bool $gapUnverified = false;

    /** @var list<array{0: int, 1: int}> */
    private array $interruptions = [];

    /** @var list<array{event: string, start: int, end: ?int, tag: ?IdleTag, note: ?string, taggedAt: ?int, confirmed: bool}> */
    private array $idle = [];

    /** @var array{tag: IdleTag, note: ?string}|null */
    private ?array $preTag = null;

    private ?string $reason = null;

    private ?string $workReport = null;

    private ?int $reportAt = null;

    private bool $lateClaim = false;

    private int $lastEvidence;

    private string $deviceId;

    /** Since when the shift runs in a web browser; null while it runs on a PC (3.11) */
    private ?int $webSince = null;

    /** Last "Masih lembur" answer while the shift runs in a browser */
    private ?int $webConfirmedAt = null;

    private readonly ShiftCorrections $corrections;

    public function __construct(private readonly ShiftInput $input)
    {
        $this->rules = $input->rules;
        $this->corrections = ShiftCorrections::fromEvents($input->events);
        $this->clockIn = $input->clockIn->ms();
        $this->cursor = $this->clockIn;
        $this->endMs = $this->clockIn;
        $this->lastEvidence = $this->clockIn;
        $this->deviceId = $input->clockIn->deviceId;
        $this->webSince = ShiftRules::isWebDevice($this->deviceId) ? $this->clockIn : null;
    }

    public function run(): ShiftResult
    {
        $events = $this->sortedEvents();

        foreach ($events as $event) {
            // 3.1.2: undo within 2 minutes removes the shift from totals
            if ($event->type === EventType::ClockInCancelled
                && $event->ms() - $this->clockIn <= ShiftRules::CANCEL_WINDOW_SECONDS * 1000) {
                return ShiftResult::cancelled($this->input->clockIn, $event->occurredAt);
            }
        }

        $this->start();

        $limit = $this->input->nextClockInAt?->getTimestampMs();
        $late = [];

        foreach ($events as $event) {
            if ($limit !== null && $event->ms() > $limit) {
                $late[] = $event;

                continue;
            }

            $this->detectCrash($event);
            $this->advance($event->ms());
            $this->handle($event);
        }

        $this->finish();

        // Written after the next shift started, for example a work report asked at the next sign-in (3.4.2)
        foreach ($late as $event) {
            $this->handleAfterEnd($event);
        }

        $this->applyCorrections();

        return $this->result();
    }

    /** @return list<ShiftEvent> */
    private function sortedEvents(): array
    {
        // Corrections are not moments in the shift: they are read once as overrides (ShiftCorrections)
        $events = array_values(array_filter(
            $this->input->events,
            fn (ShiftEvent $e) => ! in_array($e->type, [EventType::ClockIn, EventType::Heartbeat, EventType::CorrectionApplied], true) && $e->ms() >= $this->clockIn,
        ));

        usort($events, fn (ShiftEvent $a, ShiftEvent $b) => [$a->ms(), $a->id] <=> [$b->ms(), $b->id]);

        return $events;
    }

    private function start(): void
    {
        $this->reason = $this->input->clockIn->text('reason');

        if (! $this->input->isWorkday) {
            // 3.4.1: on a non-workday the whole shift is overtime
            $this->phase = self::OVERTIME;
            $this->overtimeStart = $this->clockIn;

            return;
        }

        $this->remainingMs = max(0, $this->input->regularLimitMinutes - $this->input->regularBeforeMinutes) * self::MINUTE;

        if ($this->remainingMs === 0) {
            // 3.1.5: the limit was already reached on this work date, the prompt shows right away
            $this->reachMark($this->clockIn);
        }
    }

    private function advance(int $target): void
    {
        while ($this->phase !== self::ENDED) {
            $target = max($target, $this->cursor);
            [$at, $transition] = $this->nextTransition($target);

            if ($transition === null) {
                $this->accrue($target);

                return;
            }

            $this->accrue($at);
            $transition($at);
        }
    }

    /** @return array{0: int, 1: Closure(int): void|null} */
    private function nextTransition(int $target): array
    {
        $best = $target;
        $apply = null;

        $consider = function (int $at, bool $inclusive, Closure $fn) use (&$best, &$apply, $target): void {
            $at = max($at, $this->cursor);
            $fires = $inclusive ? $at <= $target : $at < $target;

            if ($fires && ($apply === null || $at < $best)) {
                $best = $at;
                $apply = $fn;
            }
        };

        if ($this->interruptedSince !== null && $this->phase !== self::PROMPTED && ! $this->interruptionUnchecked) {
            $since = $this->interruptedSince;
            $consider($since + $this->rules->resumeWindowMinutes * self::MINUTE, false, fn () => $this->closeForReview($since));
        }

        if ($this->phase === self::REGULAR && $this->interruptedSince === null && $this->remainingMs !== null) {
            $consider($this->cursor + ($this->remainingMs - $this->activeMs), true, fn (int $at) => $this->reachMark($at));
        }

        if ($this->phase === self::PROMPTED) {
            // 3.3.5: no answer within 30 minutes closes the shift at the mark
            $consider($this->promptDeadline, false, fn () => $this->end($this->mark, EndReason::AutoNoAnswer));
        }

        // 3.11: in a browser the timer check below replaces the quiet-period check of the PC
        if ($this->phase === self::OVERTIME && $this->webSince === null) {
            foreach ($this->idle as $i => $period) {
                if ($this->presencePending($period)) {
                    $consider($this->answerBy($period), false, fn () => $this->endByPresenceCheck($i));
                }
            }
        }

        if (($webCheck = $this->webCheckAt()) !== null) {
            $consider($webCheck + $this->rules->overtimeIdleAnswerMinutes * self::MINUTE, false, fn () => $this->endByWebCheck($webCheck));
        }

        return [$best, $apply];
    }

    private function accrue(int $to): void
    {
        if ($this->phase === self::REGULAR && $this->interruptedSince === null && $this->remainingMs !== null) {
            $this->activeMs = min($this->remainingMs, $this->activeMs + max(0, $to - $this->cursor));
        }

        $this->cursor = max($this->cursor, $to);
    }

    private function reachMark(int $at): void
    {
        $this->mark = $at;
        $this->activeMs = (int) $this->remainingMs;

        if ($this->overtimeChosenEarly) {
            $this->phase = self::OVERTIME;
            $this->overtimeStart = $at;

            return;
        }

        $this->phase = self::PROMPTED;
        $this->promptDeadline = $at + $this->rules->promptAutoCloseMinutes * self::MINUTE;
    }

    private function handle(ShiftEvent $event): void
    {
        if ($this->phase === self::ENDED) {
            $this->handleAfterEnd($event);

            return;
        }

        $at = $event->ms();
        $this->lastEvidence = max($this->lastEvidence, $at);

        if ($this->interruptedSince !== null && ($event->type->showsPresence() || $this->wakesFromSleep($event))) {
            $this->endInterruption($at);
        }

        match ($event->type) {
            EventType::ClockOut => $this->clockOut($event),
            EventType::AutoClockOut => $this->autoClockOut($event),
            EventType::OvertimeStart => $this->chooseOvertime($event),
            EventType::OvertimeReason => $this->reason = $event->text('reason') ?? $this->reason,
            EventType::OvertimeReport => $this->report($event),
            EventType::IdleStart => $this->idleStart($event),
            EventType::IdleEnd => $this->idleEnd($event),
            EventType::IdleTag => $this->idleTag($event),
            EventType::PresenceConfirmed => $this->confirmPresence($at),
            EventType::PcShutdown, EventType::PcSleep => $this->interrupt($at, $event),
            EventType::ShiftMoved, EventType::ShiftResumed => $this->switchDevice($event),
            default => null,
        };
    }

    private function handleAfterEnd(ShiftEvent $event): void
    {
        match ($event->type) {
            EventType::OvertimeReport => $this->report($event),
            EventType::OvertimeReason => $this->reason = $event->text('reason') ?? $this->reason,
            EventType::IdleTag => empty($event->payload['pre_tag']) ? $this->idleTag($event) : null,
            EventType::OvertimeClaim => $this->claim($event),
            default => null,
        };
    }

    private function clockOut(ShiftEvent $event): void
    {
        if ($this->phase === self::OVERTIME) {
            $this->overtimeEnd = $event->ms();
            $this->overtimeEndReason = OvertimeEndReason::ClockOut;
        }

        $this->report($event);

        // 3.3.3: in the prompt, the shift closes at the click and the answer time is not overtime
        $this->end($event->ms(), EndReason::Manual);
    }

    private function autoClockOut(ShiftEvent $event): void
    {
        match ($this->phase) {
            self::PROMPTED => $this->end($this->mark, EndReason::AutoNoAnswer),
            // The PC reached its own mark first; the shift ends where the PC ended it
            self::REGULAR => $this->end($event->ms(), EndReason::AutoNoAnswer),
            default => null,
        };
    }

    private function chooseOvertime(ShiftEvent $event): void
    {
        $this->reason = $event->text('reason') ?? $this->reason;

        if ($this->phase === self::PROMPTED) {
            // 3.3.4: overtime starts at the 8-hour mark
            $this->phase = self::OVERTIME;
            $this->overtimeStart = $this->mark;
            $this->promptDeadline = null;
        } elseif ($this->phase === self::REGULAR) {
            $this->overtimeChosenEarly = true;
        }
    }

    private function report(ShiftEvent $event): void
    {
        $text = $event->text('work_report');

        if ($text !== null) {
            $this->workReport = $text;
            $this->reportAt = $event->ms();
        }
    }

    private function idleStart(ShiftEvent $event): void
    {
        if ($this->openIdleIndex() !== null || ! $this->fromShiftDevice($event)) {
            return;
        }

        $tag = IdleTag::tryFrom((string) ($event->payload['tag'] ?? '')) ?? $this->preTag['tag'] ?? null;

        $this->idle[] = [
            'event' => $event->id,
            'start' => $event->ms(),
            'end' => null,
            'tag' => $tag,
            'note' => $event->text('note') ?? $this->preTag['note'] ?? null,
            'taggedAt' => $tag !== null ? $event->ms() : null,
            'confirmed' => false,
        ];

        $this->preTag = null;
    }

    private function idleEnd(ShiftEvent $event): void
    {
        $index = $this->openIdleIndex();

        if ($index !== null && $this->fromShiftDevice($event)) {
            $this->idle[$index]['end'] = max($event->ms(), $this->idle[$index]['start']);
        }
    }

    private function idleTag(ShiftEvent $event): void
    {
        $tag = IdleTag::tryFrom((string) ($event->payload['tag'] ?? ''));

        if ($tag === null) {
            return;
        }

        $note = $event->text('note');

        // 3.5.4: "Aku tinggal dulu" tags the next quiet period in advance. The click is the last input, so the idle
        // start (backdated to that input) may sort a moment before the tag: a quiet period that started no more than
        // the idle threshold before the tag counts as tagged in advance.
        if (! empty($event->payload['pre_tag'])) {
            if (! $this->fromShiftDevice($event)) {
                return;
            }

            $open = $this->openIdleIndex();

            if ($open !== null && $this->idle[$open]['start'] >= $event->ms() - $this->rules->idleThresholdMinutes * self::MINUTE) {
                $this->idle[$open]['tag'] = $tag;
                $this->idle[$open]['note'] = $note;
                $this->idle[$open]['taggedAt'] = $this->idle[$open]['start'];

                return;
            }

            $this->preTag = ['tag' => $tag, 'note' => $note];

            return;
        }

        $target = null;
        $startId = $event->payload['idle_start_id'] ?? null;

        foreach ($this->idle as $i => $period) {
            if (is_string($startId) && $period['event'] === $startId) {
                $target = $i;
            }
        }

        // A tag from a device the shift moved away from only names its own quiet period (I15)
        if ($target === null && ! $this->fromShiftDevice($event)) {
            return;
        }

        $target ??= $this->openIdleIndex() ?? array_key_last($this->idle);

        if ($target === null) {
            if ($this->phase !== self::ENDED) {
                $this->preTag = ['tag' => $tag, 'note' => $note];
            }

            return;
        }

        if ($this->idle[$target]['tag'] !== $tag || $this->idle[$target]['taggedAt'] === null) {
            $this->idle[$target]['taggedAt'] = $event->ms();
        }

        $this->idle[$target]['tag'] = $tag;
        $this->idle[$target]['note'] = $note;
    }

    /**
     * 3.1.3: after a move, a PC the shift left may keep sending idle events (its app still runs). They stay in the
     * event log but open, end or pre-tag no quiet period of the moved shift (I15).
     */
    private function fromShiftDevice(ShiftEvent $event): bool
    {
        return $event->deviceId === '' || $this->deviceId === '' || $event->deviceId === $this->deviceId;
    }

    private function openIdleIndex(): ?int
    {
        foreach ($this->idle as $i => $period) {
            if ($period['end'] === null) {
                return $i;
            }
        }

        return null;
    }

    /** 3.5.2: "Masih lembur" keeps overtime running; the quiet period stays recorded */
    private function confirmPresence(int $at): void
    {
        if ($this->overtimeStart === null) {
            return;
        }

        foreach ($this->idle as $i => $period) {
            if ($this->quietStart($period) <= $at && $at <= $this->answerBy($period)) {
                $this->idle[$i]['confirmed'] = true;
            }
        }

        if ($this->webSince !== null) {
            $this->webConfirmedAt = $at;
        }
    }

    private function switchDevice(ShiftEvent $event): void
    {
        if ($event->deviceId === '' || $event->deviceId === $this->deviceId) {
            return;
        }

        $this->settleQuietTimeOnMove($event->ms());
        $this->webSince = ShiftRules::isWebDevice($event->deviceId) ? $event->ms() : null;
        $this->deviceId = $event->deviceId;
    }

    /**
     * 3.1.3 and 3.11: the person carried on at another PC or in a browser, so the open quiet period of the device they
     * left ends at the move, and quiet periods up to then no longer lead to a presence check: the device the shift runs
     * on now cannot ask about them. Its own quiet time, or the browser's timer check, applies from here (I15, W1).
     */
    private function settleQuietTimeOnMove(int $at): void
    {
        foreach ($this->idle as $i => $period) {
            if ($period['end'] === null) {
                $this->idle[$i]['end'] = max($at, $period['start']);
            }

            $this->idle[$i]['confirmed'] = true;
        }
    }

    /**
     * 3.11: a browser cannot see keyboard or mouse input, so during overtime in a browser "Masih lembur?" is asked
     * on a timer: overtime_idle_check_minutes after overtime started, the shift moved to the browser, or the last
     * answer, whichever is latest.
     */
    private function webCheckAt(): ?int
    {
        if ($this->phase !== self::OVERTIME || $this->webSince === null || $this->overtimeStart === null) {
            return null;
        }

        return max($this->overtimeStart, $this->webSince, $this->webConfirmedAt ?? 0) + $this->rules->overtimeIdleCheckMinutes * self::MINUTE;
    }

    /** 3.11: no answer ends overtime at the moment the check was shown and moves the shift to report due */
    private function endByWebCheck(int $checkAt): void
    {
        $this->overtimeEnd = $checkAt;
        $this->overtimeEndReason = OvertimeEndReason::PresenceCheckNoAnswer;
        $this->end($checkAt, EndReason::AutoNoAnswer);
    }

    /** @param array{start: int, end: ?int, tag: ?IdleTag, taggedAt: ?int, confirmed: bool} $period */
    private function presencePending(array $period): bool
    {
        if ($this->overtimeStart === null || $period['confirmed']) {
            return false;
        }

        if ($period['end'] !== null && ($period['end'] <= $this->overtimeStart || $period['end'] < $this->checkAt($period))) {
            return false;
        }

        // 3.5.4: a quiet period tagged Render in advance does not trigger the check
        return ! ($period['tag'] === IdleTag::Rendering && $period['taggedAt'] !== null && $period['taggedAt'] <= $this->checkAt($period));
    }

    /** @param array{start: int} $period */
    private function quietStart(array $period): int
    {
        return max($period['start'], (int) $this->overtimeStart);
    }

    /** @param array{start: int} $period */
    private function checkAt(array $period): int
    {
        return $this->quietStart($period) + $this->rules->overtimeIdleCheckMinutes * self::MINUTE;
    }

    /** @param array{start: int} $period */
    private function answerBy(array $period): int
    {
        return $this->checkAt($period) + $this->rules->overtimeIdleAnswerMinutes * self::MINUTE;
    }

    /** 3.5.3: no answer ends overtime at the start of the quiet period and moves the shift to report due */
    private function endByPresenceCheck(int $index): void
    {
        $quietStart = $this->quietStart($this->idle[$index]);

        $this->overtimeEnd = $quietStart;
        $this->overtimeEndReason = OvertimeEndReason::PresenceCheckNoAnswer;
        $this->end($quietStart, EndReason::AutoNoAnswer);
    }

    private function interrupt(int $at, ?ShiftEvent $cause = null): void
    {
        if ($this->phase !== self::ENDED && $this->interruptedSince === null) {
            $this->interruptedSince = $at;
            $this->interruptionCause = $cause !== null ? ['type' => $cause->type, 'device' => $cause->deviceId] : null;
        }
    }

    private function endInterruption(int $at): void
    {
        if ($this->interruptedSince === null) {
            return;
        }

        if ($at > $this->interruptedSince) {
            $this->interruptions[] = [$this->interruptedSince, $at];
        }

        $this->interruptedSince = null;
        $this->interruptionCause = null;
        $this->interruptionUnchecked = false;
    }

    /**
     * 3.7.3: waking a PC that went to sleep continues the shift. The app never stopped and nobody signed out, so a
     * wake from the same PC is the same person's session. Heartbeats alone do not end a sleep: they prove the app
     * runs, and they are not stored, so a shift could not be rebuilt from them.
     */
    private function wakesFromSleep(ShiftEvent $event): bool
    {
        return $event->type === EventType::PcWake
            && ($this->interruptionCause['type'] ?? null) === EventType::PcSleep
            && $this->interruptionCause['device'] === $event->deviceId;
    }

    /**
     * A resume after a crash has no shutdown event, so the gap starts at the latest sign of life: the last local
     * heartbeat the PC reports with the resume, the last event any PC recorded for the shift (offline ones included),
     * or the last heartbeat the server received before the resume. A silence up to two heartbeats is not a gap (3.7.3).
     *
     * The PC only sends shift_resumed after applying the resume window to its own heartbeats. When the server's
     * evidence shows a longer gap (heartbeats written offline are not uploaded), the shift still continues, the gap
     * is not paid, and the shift is flagged gap_unverified for review instead of being closed.
     */
    private function detectCrash(ShiftEvent $event): void
    {
        if ($event->type !== EventType::ShiftResumed || $this->phase === self::ENDED || $this->interruptedSince !== null) {
            return;
        }

        $signs = [$this->lastEvidence, $this->cursor];
        $seen = $event->payload['_server']['last_seen_at'] ?? null;

        if (is_string($seen)) {
            $signs[] = Time::parse($seen)->getTimestampMs();
        }

        $localBeat = $this->reportedHeartbeat($event);

        if ($localBeat !== null) {
            $signs[] = $localBeat;
        }

        $since = min(max($signs), $event->ms());
        $gap = $event->ms() - $since;

        if ($gap <= $this->rules->gapThresholdMs($localBeat !== null)) {
            return;
        }

        $this->advance($since);
        $this->interrupt($since, $event);

        if ($this->interruptedSince !== null && $gap > $this->rules->resumeWindowMinutes * self::MINUTE) {
            $this->interruptionUnchecked = true;
            $this->gapUnverified = true;
        }
    }

    /** `payload.last_heartbeat_at` on shift_resumed is PC clock time; it is moved by the same correction as the event. */
    private function reportedHeartbeat(ShiftEvent $event): ?int
    {
        $device = $event->timeMs('last_heartbeat_at');

        if ($device === null || $event->occurredAtDevice === null) {
            return null;
        }

        $at = $event->ms() - ($event->occurredAtDevice->getTimestampMs() - $device);

        return $at >= $this->clockIn && $at <= $event->ms() ? $at : null;
    }

    /** 3.7.3: nobody came back within the resume window; closed at the last sign of life and flagged */
    private function closeForReview(int $since): void
    {
        $this->interruptedSince = null;
        $this->interruptionCause = null;
        $this->interruptionUnchecked = false;
        $this->needsReview = true;
        $this->end($since, EndReason::ShutdownTimeout);
    }

    /** Latest moment the person's PCs show activity for this shift: last heartbeat or last recorded event. */
    private function lastActivityMs(): int
    {
        $latest = $this->input->lastSeenAt->getTimestampMs();

        foreach ($this->input->events as $event) {
            if (! $event->type->isServerWritten()) {
                $latest = max($latest, $event->ms());
            }
        }

        return $latest;
    }

    /** 3.3.6 and 3.5.5: a late claim extends an automatically ended shift with overtime */
    private function claim(ShiftEvent $event): void
    {
        if ($this->lateClaim || $this->endReason !== EndReason::AutoNoAnswer || $this->clockOut === null) {
            return;
        }

        if ($event->ms() > $this->clockOut + $this->rules->lateClaimHours * 60 * self::MINUTE) {
            return;
        }

        $endedAt = $event->timeMs('ended_at');

        if ($endedAt === null) {
            return;
        }

        $endedAt = min($endedAt, $event->ms(), $this->lastActivityMs(), $this->input->nextClockInAt?->getTimestampMs() ?? PHP_INT_MAX);

        if ($endedAt <= $this->clockOut) {
            return;
        }

        $this->overtimeStart ??= $this->mark ?? $this->clockOut;
        $this->overtimeEnd = $endedAt;
        $this->clockOut = $endedAt;
        $this->reason = $event->text('reason') ?? $this->reason;
        $this->report($event);
        $this->lateClaim = true;
    }

    private function end(int $at, EndReason $reason): void
    {
        $this->endInterruption($at);

        $this->clockOut = $at;
        $this->endReason = $reason;

        if ($this->overtimeStart !== null && $this->overtimeEnd === null) {
            $this->overtimeEnd = max($at, $this->overtimeStart);
        }

        $this->phase = self::ENDED;
        $this->promptDeadline = null;
    }

    private function finish(): void
    {
        $next = $this->input->nextClockInAt?->getTimestampMs();
        $end = $next ?? $this->input->now->getTimestampMs();
        $tail = min(max($this->lastEvidence, $this->input->lastSeenAt->getTimestampMs()), $end);

        // No heartbeat for a while: the PC is off, crashed, or the app was killed (3.7, section 4)
        if ($this->phase !== self::ENDED && $this->interruptedSince === null
            && $end - $tail > $this->rules->gapThresholdMs() && $tail >= $this->cursor) {
            $this->advance($tail);
            $this->interrupt($tail);
        }

        $this->advance($end);

        if ($next !== null && $this->phase !== self::ENDED) {
            // The person clocked in again without this shift being closed: close it where it was last seen
            $this->closeForReview(max($this->interruptedSince ?? $tail, $this->clockIn));
        }

        $this->endMs = $end;
    }

    /**
     * 3.10: corrected times replace what the devices and the time rules recorded, and the minutes are counted again
     * between them. The events are walked on the recorded times first, so every prompt and answer keeps the moment
     * the person saw it; the corrections are laid over the ended shift. A running shift ignores them until it ends.
     *
     * - A corrected clock-in or clock-out moves the 8-hour mark with it, and overtime that started at the recorded
     *   mark (or at clock-in on a non-workday) or ran until the recorded end moves along.
     * - Recorded interruptions still do not count (I2). The time after a shift was closed for missing heartbeats
     *   counts up to a corrected clock-out, and the review flag is cleared.
     * - Time after the 8-hour mark is overtime only from the start of overtime (3.3.3), which can itself be corrected
     *   but never to before the mark (3.4.1).
     */
    private function applyCorrections(): void
    {
        $c = $this->corrections;

        if (! $c->any() || $this->phase !== self::ENDED || $this->clockOut === null) {
            return;
        }

        $recordedStart = $this->clockIn;
        $recordedEnd = $this->clockOut;
        $recordedMark = $this->mark;

        if ($c->clockIn !== null) {
            $this->clockIn = min($c->clockIn, $c->clockOut ?? $recordedEnd);
        }

        if ($c->clockOut !== null) {
            $this->clockOut = max($c->clockOut, $this->clockIn);
            $this->endReason = EndReason::Superadmin;
            $this->needsReview = false;
        }

        if ($c->clockIn !== null || $c->clockOut !== null) {
            $this->mark = $this->markBy($this->clockOut);

            if ($this->overtimeStart !== null) {
                if (! $this->input->isWorkday && $this->overtimeStart === $recordedStart) {
                    $this->overtimeStart = $this->clockIn;
                } elseif ($this->input->isWorkday && $recordedMark !== null && $this->overtimeStart === $recordedMark) {
                    $this->overtimeStart = $this->mark ?? $this->clockOut;
                }

                if ($c->clockOut !== null && ($this->overtimeEnd === null || $this->overtimeEnd >= $recordedEnd)) {
                    $this->overtimeEnd = $this->clockOut;
                    $this->overtimeEndReason = OvertimeEndReason::ClockOut;
                }
            }
        }

        if ($c->overtimeStart !== null && $this->input->isWorkday && $this->mark !== null) {
            if ($this->overtimeStart === null) {
                $this->overtimeEnd = $this->clockOut;
                $this->overtimeEndReason = OvertimeEndReason::ClockOut;
            }

            $this->overtimeStart = max($c->overtimeStart, $this->mark);
        }

        if ($c->overtimeEnd !== null && $this->overtimeStart !== null) {
            $this->overtimeEnd = min(max($c->overtimeEnd, $this->overtimeStart), $this->clockOut);
            $this->overtimeEndReason = OvertimeEndReason::ClockOut;
        }

        // A shift that now ends at or before the start of its overtime has no overtime
        if ($this->overtimeStart !== null && $this->input->isWorkday && $this->overtimeStart >= $this->clockOut) {
            $this->overtimeStart = null;
            $this->overtimeEnd = null;
            $this->overtimeEndReason = null;
        }
    }

    /** The moment regular time reaches the limit, interruptions not counted, if that is no later than $end. */
    private function markBy(int $end): ?int
    {
        if ($this->remainingMs === null) {
            return null;
        }

        $gaps = $this->interruptions;
        usort($gaps, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $at = $this->clockIn;
        $left = $this->remainingMs;

        foreach ($gaps as [$start, $stop]) {
            if ($stop <= $at) {
                continue;
            }

            $start = max($start, $at);

            if ($start - $at >= $left) {
                break;
            }

            $left -= $start - $at;
            $at = $stop;
        }

        $mark = $at + $left;

        return $mark <= $end ? $mark : null;
    }

    private function result(): ShiftResult
    {
        $shiftEnd = max($this->clockOut ?? $this->endMs, $this->clockIn);
        $gaps = $this->interruptions;

        if ($this->interruptedSince !== null) {
            $gaps[] = [$this->interruptedSince, $shiftEnd];
        }

        $interruptionMs = $this->overlap($gaps, $this->clockIn, $shiftEnd);

        $regularMs = 0;
        $regularEndsAt = null;

        if ($this->remainingMs !== null) {
            $until = $this->mark !== null ? min($this->mark, $shiftEnd) : $shiftEnd;
            $regularMs = min($this->remainingMs, $this->active($gaps, $this->clockIn, $until));

            if ($this->remainingMs > 0) {
                $regularEndsAt = $this->mark ?? $this->clockIn + $this->remainingMs + $interruptionMs;
            }
        }

        $overtimeMs = $this->overtimeStart !== null
            ? $this->active($gaps, $this->overtimeStart, $this->overtimeEnd ?? $shiftEnd)
            : 0;

        [$idlePeriods, $idleMinutes] = $this->idleResults($shiftEnd);

        $ended = $this->phase === self::ENDED;

        $status = match (true) {
            $ended && $this->needsReview => ShiftStatus::NeedsReview,
            // 3.4.2: overtime stays in "report due" until the work report is written
            $ended && $this->overtimeStart !== null && $this->workReport === null => ShiftStatus::ReportDue,
            $ended => ShiftStatus::Closed,
            $this->interruptedSince !== null => ShiftStatus::Interrupted,
            $this->phase === self::PROMPTED => ShiftStatus::Prompted,
            $this->phase === self::OVERTIME => ShiftStatus::Overtime,
            default => ShiftStatus::Open,
        };

        $overtime = $this->overtimeStart === null ? null : new OvertimeResult(
            startedAt: Time::fromMs($this->overtimeStart),
            endedAt: $ended ? Time::fromMs($this->overtimeEnd ?? $shiftEnd) : null,
            minutes: intdiv($overtimeMs, self::MINUTE),
            reason: $this->reason,
            workReport: $this->workReport,
            reportSubmittedAt: $this->reportAt !== null ? Time::fromMs($this->reportAt) : null,
            isLateClaim: $this->lateClaim,
        );

        [$claimableUntil, $latestClaimEnd] = $this->claimWindow();

        $interruptions = array_map(fn (array $gap) => [Time::fromMs($gap[0]), Time::fromMs($gap[1])], $this->interruptions);

        if ($this->interruptedSince !== null) {
            $interruptions[] = [Time::fromMs($this->interruptedSince), null];
        }

        return new ShiftResult(
            cancelled: false,
            status: $status,
            clockInAt: $this->clockIn !== $this->input->clockIn->ms() ? Time::fromMs($this->clockIn) : $this->input->clockIn->occurredAt,
            clockOutAt: $this->clockOut !== null ? Time::fromMs($this->clockOut) : null,
            regularEndsAt: $regularEndsAt !== null ? Time::fromMs($regularEndsAt) : null,
            promptDeadlineAt: $this->phase === self::PROMPTED ? Time::fromMs((int) $this->promptDeadline) : null,
            endReason: $this->endReason,
            overtimeEndReason: $this->overtimeEndReason,
            regularMinutes: intdiv($regularMs, self::MINUTE),
            overtimeMinutes: intdiv($overtimeMs, self::MINUTE),
            idleMinutes: $idleMinutes,
            interruptionMinutes: intdiv($interruptionMs, self::MINUTE),
            idlePeriods: $idlePeriods,
            interruptions: $interruptions,
            overtime: $overtime,
            flags: $this->flags(),
            lastSeenAt: Time::fromMs(max($this->lastEvidence, $this->input->lastSeenAt->getTimestampMs())),
            deviceId: $this->deviceId,
            claimableUntil: $claimableUntil !== null ? Time::fromMs($claimableUntil) : null,
            latestClaimEndAt: $latestClaimEnd !== null ? Time::fromMs($latestClaimEnd) : null,
            webPresenceCheckAt: ($webCheck = $this->webCheckAt()) !== null ? Time::fromMs($webCheck) : null,
            webPresenceAnswerBy: $webCheck !== null ? Time::fromMs($webCheck + $this->rules->overtimeIdleAnswerMinutes * self::MINUTE) : null,
        );
    }

    /**
     * 3.3.6 and 3.5.5: while a late claim is possible, until when, and the latest end time it may give. The end is
     * bounded by the last activity recorded on the PC, the next clock-in, and now; with no activity after the
     * automatic end there is nothing to claim.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function claimWindow(): array
    {
        if ($this->phase !== self::ENDED || $this->endReason !== EndReason::AutoNoAnswer || $this->lateClaim || $this->clockOut === null) {
            return [null, null];
        }

        $now = $this->input->now->getTimestampMs();
        $until = $this->clockOut + $this->rules->lateClaimHours * 60 * self::MINUTE;
        $latest = min($this->lastActivityMs(), $this->input->nextClockInAt?->getTimestampMs() ?? PHP_INT_MAX, $now);

        return $now <= $until && $latest > $this->clockOut ? [$until, $latest] : [null, null];
    }

    /** @return array{0: list<IdlePeriodResult>, 1: int} */
    private function idleResults(int $shiftEnd): array
    {
        $periods = [];
        $total = 0;

        foreach ($this->idle as $period) {
            if ($this->clockOut !== null && $period['start'] >= $this->clockOut) {
                continue;
            }

            // Only a corrected clock-in can be later than a quiet period (3.10)
            if ($period['end'] !== null && $period['end'] < $this->clockIn) {
                continue;
            }

            $period['start'] = max($period['start'], $this->clockIn);

            $end = $period['end'];

            if ($this->clockOut !== null) {
                $end = min($end ?? $this->clockOut, $this->clockOut);
            }

            // 3.6.5: idle minutes are shown, never subtracted
            $minutes = intdiv(max(0, ($end ?? $shiftEnd) - $period['start']), self::MINUTE);
            $total += $minutes;

            $periods[] = new IdlePeriodResult(
                startedAt: Time::fromMs($period['start']),
                endedAt: $end !== null ? Time::fromMs($end) : null,
                minutes: $minutes,
                tag: $period['tag'],
                note: $period['note'],
            );
        }

        return [$periods, $total];
    }

    /** @return list<ShiftFlag> */
    private function flags(): array
    {
        $flags = [];
        $threshold = $this->rules->clockMismatchSeconds;

        $mismatch = $this->input->clockIn->clockMismatch($threshold);

        foreach ($this->input->events as $event) {
            $mismatch = $mismatch || $event->clockMismatch($threshold);
        }

        // 3.9.4
        if ($mismatch) {
            $flags[] = ShiftFlag::ClockMismatch;
        }

        if ($this->input->clockIn->offline) {
            $flags[] = ShiftFlag::OfflineSignIn;
        }

        if ($this->lateClaim) {
            $flags[] = ShiftFlag::LateClaim;
        }

        if ($this->gapUnverified) {
            $flags[] = ShiftFlag::GapUnverified;
        }

        return $flags;
    }

    /** @param list<array{0: int, 1: int}> $gaps */
    private function overlap(array $gaps, int $from, int $to): int
    {
        $total = 0;

        foreach ($gaps as [$start, $end]) {
            $total += max(0, min($end, $to) - max($start, $from));
        }

        return $total;
    }

    /** @param list<array{0: int, 1: int}> $gaps */
    private function active(array $gaps, int $from, int $to): int
    {
        return max(0, $to - $from - $this->overlap($gaps, $from, $to));
    }
}
