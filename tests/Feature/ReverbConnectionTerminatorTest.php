<?php

use App\Support\ReverbConnectionTerminator;
use Illuminate\Support\Facades\Broadcast;
use Pusher\Pusher;

it('closes existing sockets when reverb is active', function () {
    config(['broadcasting.default' => 'reverb']);

    $pusher = Mockery::mock(Pusher::class);
    $pusher->shouldReceive('terminateUserConnections')->once()->with('42');

    $broadcaster = new class($pusher)
    {
        public function __construct(private $pusher) {}

        public function getPusher(): Pusher
        {
            return $this->pusher;
        }
    };

    Broadcast::shouldReceive('connection')->once()->with('reverb')->andReturn($broadcaster);

    ReverbConnectionTerminator::forUser(42);
});

it('is skipped for non reverb drivers', function () {
    config(['broadcasting.default' => 'null']);

    Broadcast::shouldReceive('connection')->never();

    ReverbConnectionTerminator::forUser(42);
});
