<?php

namespace Pterodactyl\Classes\Cloudflare\Traits;

trait BodyAccessorTrait
{
    private $body;

    public function getBody()
    {
        return $this->body;
    }
}
