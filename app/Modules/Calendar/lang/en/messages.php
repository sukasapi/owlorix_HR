<?php

return [
    'work_week_saved' => 'Studio work week saved. Shifts already recorded will be recalculated.',
    'work_week_unchanged' => 'The work week did not change, so nothing was saved.',

    'entry_created' => ':name on :date is now on the calendar.',
    'entry_updated' => 'Saved :name on :date.',
    'entry_unchanged' => 'This entry did not change, so nothing was saved.',
    'entry_deleted' => 'Removed :name on :date from the calendar.',
    'entry_exists' => 'This date already has the entry ":name". A date can have only one entry, so edit that one instead.',

    'opened' => ':date is open as a workday for :scope.',
    'closed' => ':date is no longer open for :scope.',
    'already_opened' => ':date is already open for :scope.',
    'already_workday_team' => ':date is already a workday for every member of :scope, so there is nothing to open.',
    'already_workday_user' => ':date is already a workday for :scope, so there is nothing to open.',

    'deleted_team' => 'a deleted team',
    'deleted_person' => 'a deleted person',

    'attributes' => [
        'date' => 'date',
        'type' => 'type',
        'name' => 'name',
        'scope_type' => 'scope',
        'scope_id' => 'team or person',
        'note' => 'note',
        'workdays' => 'workdays',
    ],
];
