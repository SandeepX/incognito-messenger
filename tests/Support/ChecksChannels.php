<?php

namespace Tests\Support;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use ReflectionProperty;

trait ChecksChannels
{
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
