<?php
/**
 * User: junade
 * Date: 01/02/2017
 * Time: 12:31
 */

namespace Pterodactyl\Classes\Cloudflare\Endpoints;

use Pterodactyl\Classes\Cloudflare\Adapter\Adapter;

interface API
{
    public function __construct(Adapter $adapter);
}
