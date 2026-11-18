<?php

namespace Tests\Support;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use ReflectionProperty;

trait ChecksChannels
{
    /**
     * Invoke the real callback registered in routes/channels.php.
     *
     * The test suite uses the "null" broadcast driver so no HTTP requests
     * are made, which means the HTTP auth endpoint cannot exercise these
     * callbacks. Calling them directly still verifies the exact security
     * rules that Reverb uses in production.
     */
    protected function channelAllows(string $pattern, $user, ...$parameters): bool
    {
        $property = new ReflectionProperty(Broadcaster::class, 'channels');
        $property->setAccessible(true);

        $channels = $property->getValue(Broadcast::driver());
        $callback = $channels[$pattern] ?? null;

        $this->assertNotNull($callback, "No channel callback registered for {$pattern}");

        return (bool) $callback($user, ...$parameters);
    }
}
