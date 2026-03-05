<?php

namespace Pterodactyl\BlueprintFramework\Services\PlaceholderService;

class BlueprintPlaceholderService
{
  public function version(): string
  {
    $ver = "beta-2026-01";
    if ($ver == '::'.'v') {
      return 'unknown';
    }
    return $ver;
  }
  public function folder(): string
  {
    return base_path();
  }
  public function installed(): string
  {
    return "INSTALLED";
  }
  public function api_url(): string
  {
    return "https://blueprint.zip";
  }
}
