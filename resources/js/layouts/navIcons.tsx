import {
    CalendarBlank,
    CalendarDots,
    ChartBar,
    ClockCounterClockwise,
    Desktop,
    HourglassMedium,
    House,
    type Icon,
    ListChecks,
    NotePencil,
    Sliders,
    UsersFour,
    Users,
    UsersThree,
} from '@phosphor-icons/react';

/** Phosphor Bold icons per navigation key, as in the mockup kit. */
export const navIcons: Record<string, Icon> = {
    my_day: House,
    history: CalendarBlank,
    overtime: HourglassMedium,
    team_today: Users,
    approvals: ListChecks,
    reports: ChartBar,
    calendar: CalendarDots,
    people: UsersThree,
    teams: UsersFour,
    corrections: NotePencil,
    devices: Desktop,
    rules: Sliders,
    audit: ClockCounterClockwise,
};
