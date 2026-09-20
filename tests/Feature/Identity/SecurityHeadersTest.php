<?php

it('sends anti-framing and no-sniff headers on web pages and the health check', function (string $url) {
    $this->get($url)
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin');
})->with(['sign-in page' => '/masuk', 'health check' => '/up']);

it('sends the same headers on the desktop API', function () {
    $this->postJson('/api/v1/auth/device-login', [])
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});
