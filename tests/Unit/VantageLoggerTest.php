<?php

use Storvia\Vantage\Support\VantageLogger;
use Illuminate\Support\Facades\Log;

it('logs when enabled', function () {
    Log::spy();
    config()->set('vantage.logging.enabled', true);

    VantageLogger::info('testing', ['foo' => 'bar']);

    Log::shouldHaveReceived('info')->once()->with('testing', ['foo' => 'bar']);
});

it('does not log when disabled', function () {
    Log::spy();
    config()->set('vantage.logging.enabled', false);

    VantageLogger::info('testing', ['foo' => 'bar']);

    Log::shouldNotHaveReceived('info');
});
