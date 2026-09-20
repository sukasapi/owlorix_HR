<?php

use App\Modules\Identity\Access\Role;
use Tests\Feature\Attendance\Support\Desk;

it('shows today\'s running shift and minutes on Hari ini', function () {
    $person = userWithRole(Role::Employee);
    $desk = Desk::for($this, $person);

    $desk->send('clock_in', at: '2026-09-14 09:00');
    $desk->heartbeat('11:30');

    $this->actingAs($person)->get(route('my-day'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('my-day/Index')
            ->where('summary.date', '2026-09-14')
            ->where('summary.status', 'open')
            ->where('summary.regular_minutes', 150)
            ->has('summary.shifts', 1)
            ->where('day.is_workday', true));
});

it('shows an empty day when nothing was recorded', function () {
    $person = userWithRole(Role::Employee);
    $this->travelTo(Desk::time('2026-09-14 08:00'));

    $this->actingAs($person)->get(route('my-day'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.status', 'signed_out')
            ->has('summary.shifts', 0));
});
