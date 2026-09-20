export default {
    title: 'Rules',
    intro: 'The numbers the attendance engine uses. Each rule names its section in the attendance rules document.',
    applies_title: 'When a change takes effect',
    applies_body: 'The regular hour limit is copied onto each shift at clock-in, so a change applies to new shifts only. Every other value is read each time a shift is calculated, so it also applies to shifts that are running. The desktop app uses new values after it fetches the settings from the server again.',
    groups: {
        work_hours: 'Work hours',
        overtime: 'Overtime',
        idle: 'Idle PC',
        desktop_sync: 'Desktop sync',
        web: 'Web attendance',
    },
    group_intro: {
        work_hours: 'The daily limit and the 8-hour prompt.',
        overtime: 'The still-working check and late claims.',
        idle: 'When the desktop app records a PC as idle.',
        desktop_sync: 'Signs of activity, interrupted shifts, offline sign-in, and PC clocks.',
        web: 'Clocking in from a browser, phones included.',
    },
    units: {
        minutes: 'minutes',
        hours: 'hours',
        seconds: 'seconds',
        days: 'days',
    },
    rule: 'Rule :rule',
    range: 'Between :min and :max :unit.',
    default: 'Default: :value',
    reset: 'Back to default',
    reset_label: 'Set :label back to the default, :value',
    changed_by: 'Last changed by :name, :time',
    changed_at: 'Last changed :time',
    never_changed: 'Never changed',
    applies: {
        new_shifts: 'New shifts only',
        calculation: 'Running shifts too',
        desktop: 'Used by the desktop app',
        web: 'Applies at once',
    },
    new_shifts_note: 'Shifts already running keep :value.',
    on: 'On',
    off: 'Off',
    timezone: {
        label: 'Studio time zone',
        help: 'Every time is shown in this zone. It cannot be changed from this page.',
    },
    fields: {
        attendance: {
            regular_limit_minutes: {
                label: 'Regular hour limit per day',
                help: 'Regular work per work date, all shifts of that date added up, with no break deducted. When the limit is reached, the 8-hour prompt appears.',
            },
            prompt_repeat_minutes: {
                label: 'Repeat the 8-hour prompt every',
                help: 'While the 8-hour prompt has no answer, it appears again at this interval. It cannot be longer than the time to answer the prompt.',
            },
            prompt_auto_close_minutes: {
                label: 'Time to answer the 8-hour prompt',
                help: 'With no answer for this long, the shift closes at the 8-hour mark. The person can still file a late overtime claim.',
            },
            overtime_idle_check_minutes: {
                label: 'Ask if still working after',
                help: 'During overtime on a PC, the Still working? question appears after the PC is idle this long without a tag. In a browser it appears at this interval after overtime starts or after the last answer.',
            },
            overtime_idle_answer_minutes: {
                label: 'Time to answer still working',
                help: 'With no answer for this long, overtime stops counting at the start of the idle time (in a browser, when the question appeared), and the shift waits for a work report.',
            },
            idle_threshold_minutes: {
                label: 'A PC counts as idle after',
                help: 'With no typing or mouse movement for this long, the desktop app records idle time. Idle time is not taken off work hours.',
            },
            resume_window_minutes: {
                label: 'Time to continue an interrupted shift',
                help: 'When a PC shuts down or signs of activity stop, the person can continue the shift within this time of the last sign of activity, and the gap is recorded. After that, the shift closes and is marked for review.',
            },
            offline_sign_in_days: {
                label: 'Offline sign-in allowed for',
                help: 'Offline sign-in in the desktop app works only for people who signed in with internet on that PC within this time.',
            },
            clock_mismatch_seconds: {
                label: 'PC clock difference to flag',
                help: 'When a PC clock differs from the server clock by more than this, the shift is flagged for a PC clock check.',
            },
            web_clock_in: {
                label: 'Clock in from the web',
                help: 'When off, My Day shows no clock buttons and clocking in from a browser is refused. The desktop app keeps working. A shift running in a browser becomes interrupted and then closes for review.',
            },
        },
        overtime: {
            late_claim_hours: {
                label: 'Late overtime claim window',
                help: 'After a shift or overtime is closed automatically, the person can file a late overtime claim for this long.',
            },
        },
        sync: {
            heartbeat_local_seconds: {
                label: 'Record a sign of activity every',
                help: 'The desktop app records a sign of activity on the PC, and My Day sends one from the browser, at this interval.',
            },
            heartbeat_upload_seconds: {
                label: 'Send signs of activity to the server every',
                help: 'The longest gap between uploads from a PC while a shift is open. A shift counts as interrupted after two of these intervals without news. It cannot be shorter than the recording interval.',
            },
        },
    },
    save: 'Save rules',
    saving: 'Saving...',
    discard: 'Discard changes',
    unsaved_one: ':count unsaved change',
    unsaved_other: ':count unsaved changes',
    saved: 'Rules saved.',
    nothing_changed: 'Nothing changed.',
    failed: 'The rules were not saved. Check the connection to the studio server, then try again.',
    has_errors: 'Some values need fixing. See the red messages under their fields.',
    errors: {
        integer: 'Enter a whole number.',
        boolean: 'Choose on or off.',
        range: 'Enter a value between :min and :max :unit.',
        upload_below_local: 'Cannot be shorter than the recording interval (:value seconds).',
        repeat_above_close: 'Cannot be longer than the time to answer the 8-hour prompt (:value minutes).',
    },
};
