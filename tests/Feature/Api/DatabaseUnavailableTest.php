<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

function throwDatabaseError(string $message): never
{
    throw new QueryException('mysql', 'select * from `personal_access_tokens` where `id` = ? limit 1', [42], new PDOException($message));
}

beforeEach(function () {
    Route::get('api/v1/test-db-down', fn () => throwDatabaseError('SQLSTATE[HY000] [2002] Connection refused'));
    Route::get('test-db-down', fn () => throwDatabaseError('SQLSTATE[HY000] [2002] Connection refused'));
    Route::get('test-db-bug', fn () => throwDatabaseError('SQLSTATE[42S22]: Column not found: 1054 Unknown column'));
});

it('answers the desktop with JSON 503 while MySQL refuses connections', function () {
    $this->get('/api/v1/test-db-down')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '30')
        ->assertJsonPath('code', 'database_unavailable');
});

it('shows a browser a page that tells them to reload instead of a 500', function () {
    $this->get('/test-db-down?tab=today')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '30')
        ->assertSee(__('errors.database.title'))
        ->assertSee(__('errors.desktop'))
        ->assertSee('href="'.url('/test-db-down?tab=today').'"', escape: false);
});

it('leaves other database errors as a 500', function () {
    config(['app.debug' => false]);

    $this->get('/test-db-bug')->assertStatus(500)->assertSee(__('errors.500.title'));
});
