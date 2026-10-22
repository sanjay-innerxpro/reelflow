<?php

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Services\Publishing\Publishers\FacebookReelsPublisher;
use App\Services\Publishing\Publishers\InstagramReelsPublisher;
use App\Services\Publishing\Publishers\YouTubeShortsPublisher;
use Illuminate\Contracts\Container\Container;

class PublisherRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(Platform $platform): SocialPublisherInterface
    {
        return $this->container->make(match ($platform) {
            Platform::Facebook => FacebookReelsPublisher::class,
            Platform::Instagram => InstagramReelsPublisher::class,
            Platform::YouTube => YouTubeShortsPublisher::class,
        });
    }
}
