<?php

/**
 * BlueprintExtensionLibrary (Backwards compatibility)
 *
 * BlueprintLegacyLibrary provides backwards-compatibility for older
 * extensions. Functions are deprecated, unmaintained and slowly phased out.
 * Consider using maintained versions of BlueprintExtensionLibrary.
 *
 * Certain functions are being phased out and return "false" instead of the
 * correct value. Consider switching to maintained versions to prevent your
 * extension from breaking with future updates.
 *
 * @category   BlueprintExtensionLibrary
 * @package    BlueprintLegacyLibrary
 * @author     Emma <hello@prpl.wtf>
 * @copyright  2023-2024 Emma (prpl.wtf)
 * @license    https://blueprint.zip/docs/?page=about/License MIT License
 * @link       https://blueprint.zip/docs/?page=documentation/$blueprint
 * @since      indev
 * @deprecated alpha
 */

namespace Pterodactyl\Services\Helpers;

use Illuminate\Support\Facades\Log;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\BlueprintBaseLibrary;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary;
use Pterodactyl\BlueprintFramework\Services\PlaceholderService\BlueprintPlaceholderService;

class BlueprintExtensionLibrary
{
  private static array $warnedMethods = [];

  public function __construct(
    private SettingsRepositoryInterface $settings,
    private BlueprintPlaceholderService $placeholder,
    private BlueprintBaseLibrary $baseLibrary,
    private BlueprintAdminLibrary $adminLibrary,
  ) {
  }

  private function warnLegacyMethod(string $method): void
  {
    if (isset(self::$warnedMethods[$method])) {
      return;
    }

    self::$warnedMethods[$method] = true;

    Log::warning('Blueprint legacy helper method used.', [
      'method' => static::class . '::' . $method,
      'removal_target' => '2026-12',
    ]);
  }

  public function dbGet($table, $record)
  {
    return $this->settings->get($table . "::" . $record);
  }
  public function dbSet($table, $record, $value)
  {
    return $this->settings->set($table . "::" . $record, $value);
  }

  public function notify($text)
  {
    $this->warnLegacyMethod(__FUNCTION__);
    $this->adminLibrary->alert('info', (string) $text);

    return true;
  }
  public function notifyAfter($delay, $text)
  {
    $this->warnLegacyMethod(__FUNCTION__);

    return $this->notify($text);
  }
  public function notifyNow($text)
  {
    $this->warnLegacyMethod(__FUNCTION__);

    return $this->notify($text);
  }

  public function fileRead($path)
  {
    $this->warnLegacyMethod(__FUNCTION__);

    return $this->baseLibrary->readPathContents((string) $path) ?? '';
  }
  public function fileMake($path)
  {
    $this->warnLegacyMethod(__FUNCTION__);

    return $this->baseLibrary->createPathFile((string) $path);
  }
  public function fileWipe($path)
  {
    $this->warnLegacyMethod(__FUNCTION__);

    return $this->baseLibrary->wipePath((string) $path);
  }
}
