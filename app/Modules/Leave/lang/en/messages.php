<?php

return [
    'requested' => 'Your :type request for :days workdays is sent. The decision shows on this page.',
    'approved' => 'Leave for :name approved.',
    'rejected' => 'Leave for :name rejected.',
    'cancelled_own' => 'Leave request cancelled.',
    'cancelled_other' => 'Leave for :name cancelled.',
    'quota_saved' => 'Quota for :name in :year is now :days days.',
    'type_created' => 'Leave type :name added.',
    'type_updated' => 'Leave type :name saved.',

    'type_required' => 'Choose a leave type.',
    'type_inactive' => 'This leave type is no longer used. Choose another one.',
    'start_required' => 'Fill in the start date.',
    'end_required' => 'Fill in the end date.',
    'end_after_start' => 'The end date cannot be before the start date.',
    'same_year' => 'Start and end must be in the same year. Send one request per year.',
    'too_long' => 'One request covers at most :days calendar days.',
    'too_early' => 'The start date can be at most :days days ago.',
    'too_late' => 'The end date can be at most the end of next year.',
    'no_workdays' => 'There are no workdays on these dates. Holidays and weekends need no request.',
    'overlap' => 'These dates overlap another request of yours (:type, :from to :until).',
    'reason_required' => 'This leave type needs a reason.',
    'reason_max' => 'The reason can be at most 2000 characters.',
    'attachment_type' => 'The attachment must be a PDF or an image (JPG, PNG, WebP).',
    'attachment_size' => 'The attachment can be at most :mb MB.',

    'own_decision' => 'You cannot decide your own leave request.',
    'not_decider' => 'You cannot decide leave for this person.',
    'already_closed' => 'This request is already :status. Reload the page to see where it stands.',
    'reject_note' => 'Write why you reject it, so the person knows.',
    'note_max' => 'The note can be at most 2000 characters.',
    'cancel_started' => 'Leave that has started cannot be cancelled by yourself. Ask Superadmin.',
    'cancel_not_allowed' => 'You cannot cancel this request.',
    'cancel_note' => 'Write why you cancel it, so the person knows.',

    'status' => [
        'pending' => 'pending',
        'approved' => 'approved',
        'rejected' => 'rejected',
        'cancelled' => 'cancelled',
    ],

    'quota_days' => 'The quota is 0 to 365 days.',
    'quota_year' => 'Invalid year.',
    'type_name_required' => 'The leave type needs a name.',
    'type_name_taken' => 'A leave type with this name already exists.',
];
