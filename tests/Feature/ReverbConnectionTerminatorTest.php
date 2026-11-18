<?php

namespace Tests\Feature;

use App\Support\ReverbConnectionTerminator;
use Illuminate\Support\Facades\Broadcast;
use Mockery;
use Pusher\Pusher;
use Tests\TestCase;

class ReverbConnectionTerminatorTest extends TestCase
{
    public function test_it_closes_existing_sockets_when_reverb_is_active(): void
    {
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
    }

    public function test_it_is_skipped_for_non_reverb_drivers(): void
    {
        config(['broadcasting.default' => 'null']);

        Broadcast::shouldReceive('connection')->never();

        ReverbConnectionTerminator::forUser(42);
    }
}
