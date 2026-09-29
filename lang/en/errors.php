<?php

return [
    'code' => 'Code :status',
    'home' => 'Open My day',
    'back' => 'Back to the previous page',
    'reload' => 'Reload page',
    'desktop' => 'Clock times from the desktop app stay saved on the PC and sync on their own once the server is back.',

    '403' => [
        'title' => 'This page isn\'t open to your account',
        'body' => 'Your account doesn\'t have access here yet. If your work needs this page, ask a Superadmin to add the access.',
    ],
    '404' => [
        'title' => 'This page doesn\'t exist',
        'body' => 'The address may have a typo, or the page was moved or removed.',
    ],
    '419' => [
        'title' => 'This page was open too long',
        'body' => 'For security, a page left open for a long time has to be reloaded before it can send data. Anything you had not sent yet needs to be filled in again.',
    ],
    '429' => [
        'title' => 'Give it a moment',
        'body' => 'Too many requests came in at once. Wait about a minute, then try again.',
    ],
    '4xx' => [
        'title' => 'This request can\'t be handled',
        'body' => 'Go back to the previous page, or start again from My day.',
    ],
    '500' => [
        'title' => 'Something went wrong on the server',
        'body' => 'Your request failed, and it isn\'t your fault. Try again in a moment. If it keeps happening, tell a Superadmin what time it happened.',
    ],
    '503' => [
        'title' => 'The app is under maintenance',
        'body' => 'We\'re updating the app. Try reloading in a few minutes.',
    ],
    'database' => [
        'title' => 'Data can\'t be opened yet',
        'body' => 'The server has lost its connection to the database. Try reloading in a moment.',
    ],

    'database_api' => 'The server has lost its connection to the database. Your data stays on this PC and is sent again later.',
];
