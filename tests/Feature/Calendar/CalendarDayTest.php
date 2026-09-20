<?php

use App\Modules\Calendar\Enums\CalendarDayType;
use App\Modules\Calendar\Events\CalendarDatesChanged;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Identity\Access\Role;
use App\Modules\Shared\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([CalendarDatesChanged::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-14 03:00:00', 'UTC'));
    $this->admin = userWithRole(Role::Superadmin);
});

it('adds a calendar entry with an audit row and an event for that date', function (string $type) {
    $this->actingAs($this->admin)
        ->from(route('calendar.index', ['bulan' => '2026-10']))
        ->post(route('calendar.days.store'), ['date' => '2026-10-02', 'type' => $type, 'name' => 'Libur contoh'])
        ->assertRedirect(route('calendar.index', ['bulan' => '2026-10']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    $day = CalendarDay::query()->sole();
    expect($day->date->toDateString())->toBe('2026-10-02')
        ->and($day->type->value)->toBe($type)
        ->and($day->created_by)->toBe($this->admin->id);

    $log = AuditLog::query()->where('action', 'calendar.day.created')->sole();
    expect($log->subject_id)->toBe($day->id)
        ->and($log->before)->toBeNull()
        ->and($log->after)->toEqual(['date' => '2026-10-02', 'type' => $type, 'name' => 'Libur contoh']);

    Event::assertDispatched(CalendarDatesChanged::class, fn (CalendarDatesChanged $e) => $e->dates === ['2026-10-02'] && $e->userIds === null && ! $e->allDates);
})->with(['holiday', 'studio_day_off', 'workday']);

it('allows an entry on a date in the past', function () {
    $this->actingAs($this->admin)
        ->post(route('calendar.days.store'), ['date' => '2026-08-17', 'type' => 'holiday', 'name' => 'Libur contoh'])
        ->assertSessionHasNoErrors();

    expect(CalendarDay::query()->count())->toBe(1);
});

it('keeps one entry per date', function () {
    CalendarDay::query()->create(['date' => '2026-10-02', 'type' => CalendarDayType::Holiday, 'name' => 'Sudah ada', 'created_by' => $this->admin->id]);

    $this->actingAs($this->admin)
        ->post(route('calendar.days.store'), ['date' => '2026-10-02', 'type' => 'studio_day_off', 'name' => 'Kedua'])
        ->assertSessionHasErrors(['date' => __('calendar::messages.entry_exists', ['name' => 'Sudah ada'])]);

    expect(CalendarDay::query()->count())->toBe(1);
    Event::assertNotDispatched(CalendarDatesChanged::class);
});

it('validates date, type, and name', function (array $payload, array $errors) {
    $this->actingAs($this->admin)
        ->post(route('calendar.days.store'), $payload)
        ->assertSessionHasErrors($errors);

    expect(CalendarDay::query()->count())->toBe(0);
})->with([
    'empty' => [[], ['date', 'type', 'name']],
    'impossible date' => [['date' => '2026-02-30', 'type' => 'holiday', 'name' => 'X'], ['date']],
    'wrong date format' => [['date' => '02/10/2026', 'type' => 'holiday', 'name' => 'X'], ['date']],
    'unknown type' => [['date' => '2026-10-02', 'type' => 'vacation', 'name' => 'X'], ['type']],
    'name too long' => [['date' => '2026-10-02', 'type' => 'holiday', 'name' => str_repeat('a', 121)], ['name']],
]);

it('edits an entry, moving it to another date, and reports both dates', function () {
    $day = CalendarDay::query()->create(['date' => '2026-10-02', 'type' => CalendarDayType::Holiday, 'name' => 'Lama', 'created_by' => $this->admin->id]);

    $this->actingAs($this->admin)
        ->put(route('calendar.days.update', $day), ['date' => '2026-10-05', 'type' => 'studio_day_off', 'name' => 'Baru'])
        ->assertSessionHasNoErrors();

    expect($day->fresh())
        ->date->toDateString()->toBe('2026-10-05')
        ->type->toBe(CalendarDayType::StudioDayOff)
        ->name->toBe('Baru');

    $log = AuditLog::query()->where('action', 'calendar.day.updated')->sole();
    expect($log->before)->toEqual(['date' => '2026-10-02', 'type' => 'holiday', 'name' => 'Lama'])
        ->and($log->after)->toEqual(['date' => '2026-10-05', 'type' => 'studio_day_off', 'name' => 'Baru']);

    Event::assertDispatched(CalendarDatesChanged::class, fn (CalendarDatesChanged $e) => $e->dates === ['2026-10-02', '2026-10-05']);
});

it('keeps its own date when editing only the name', function () {
    $day = CalendarDay::query()->create(['date' => '2026-10-02', 'type' => CalendarDayType::Holiday, 'name' => 'Lama', 'created_by' => $this->admin->id]);

    $this->actingAs($this->admin)
        ->put(route('calendar.days.update', $day), ['date' => '2026-10-02', 'type' => 'holiday', 'name' => 'Baru'])
        ->assertSessionHasNoErrors();

    Event::assertDispatched(CalendarDatesChanged::class, fn (CalendarDatesChanged $e) => $e->dates === ['2026-10-02']);
});

it('refuses to move an entry onto a date that already has one', function () {
    CalendarDay::query()->create(['date' => '2026-10-05', 'type' => CalendarDayType::Holiday, 'name' => 'Lain', 'created_by' => $this->admin->id]);
    $day = CalendarDay::query()->create(['date' => '2026-10-02', 'type' => CalendarDayType::Holiday, 'name' => 'Lama', 'created_by' => $this->admin->id]);

    $this->actingAs($this->admin)
        ->put(route('calendar.days.update', $day), ['date' => '2026-10-05', 'type' => 'holiday', 'name' => 'Lama'])
        ->assertSessionHasErrors('date');

    expect($day->fresh()->date->toDateString())->toBe('2026-10-02');
});

it('writes nothing when an edit changes nothing', function () {
    $day = CalendarDay::query()->create(['date' => '2026-10-02', 'type' => CalendarDayType::Holiday, 'name' => 'Sama', 'created_by' => $this->admin->id]);

    $this->actingAs($this->admin)
        ->put(route('calendar.days.update', $day), ['date' => '2026-10-02', 'type' => 'holiday', 'name' => 'Sama'])
        ->assertSessionHasNoErrors();

    expect(AuditLog::query()->count())->toBe(0);
    Event::assertNotDispatched(CalendarDatesChanged::class);
});

it('deletes an entry with an audit row and an event', function () {
    $day = CalendarDay::query()->create(['date' => '2026-10-02', 'type' => CalendarDayType::Holiday, 'name' => 'Hapus', 'created_by' => $this->admin->id]);

    $this->actingAs($this->admin)
        ->delete(route('calendar.days.destroy', $day))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    expect(CalendarDay::query()->count())->toBe(0);

    $log = AuditLog::query()->where('action', 'calendar.day.deleted')->sole();
    expect($log->before)->toEqual(['date' => '2026-10-02', 'type' => 'holiday', 'name' => 'Hapus'])
        ->and($log->after)->toBeNull();

    Event::assertDispatched(CalendarDatesChanged::class, fn (CalendarDatesChanged $e) => $e->dates === ['2026-10-02']);
});

it('refuses calendar entries to Management', function (Role $role) {
    $person = userWithRole($role);
    $day = CalendarDay::query()->create(['date' => '2026-10-02', 'type' => CalendarDayType::Holiday, 'name' => 'Tetap', 'created_by' => $this->admin->id]);

    $this->actingAs($person)->post(route('calendar.days.store'), ['date' => '2026-10-03', 'type' => 'holiday', 'name' => 'X'])->assertForbidden();
    $this->actingAs($person)->put(route('calendar.days.update', $day), ['date' => '2026-10-02', 'type' => 'holiday', 'name' => 'X'])->assertForbidden();
    $this->actingAs($person)->delete(route('calendar.days.destroy', $day))->assertForbidden();

    expect(CalendarDay::query()->sole()->name)->toBe('Tetap');
    Event::assertNotDispatched(CalendarDatesChanged::class);
})->with([Role::TeamLead, Role::ProjectManager, Role::ProjectDirector, Role::Employee]);
