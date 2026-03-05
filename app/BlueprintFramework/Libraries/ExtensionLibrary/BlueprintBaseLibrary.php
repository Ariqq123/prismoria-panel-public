<?php

/**
 * BlueprintExtensionLibrary (Base code, do not use directly)
 *
 * @category   BlueprintExtensionLibrary
 * @package    BlueprintBaseLibrary
 * @author     Blueprint Framework <byte@blueprint.zip>
 * @copyright  2023-2026 Emma (prpl.wtf)
 * @license    https://blueprint.zip/docs/?page=about/License MIT License
 * @link       https://blueprint.zip/docs/?page=documentation/$blueprint
 * @since      alpha
 */

namespace Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;

class BlueprintBaseLibrary
{
  private const INSTALLED_EXTENSIONS_PATH = '.blueprint/extensions/blueprint/private/db/installed_extensions';
  private static array $warnedLegacyMethods = [];

  private function getRecordName(string $table, string $record)
  {
    return "$table::$record";
  }

  public function readPathContents(string $path): ?string
  {
    if (!is_file($path) || !is_readable($path)) {
      return null;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
      Log::warning('Blueprint failed to read file.', ['path' => $path]);

      return null;
    }

    return $contents;
  }

  public function createPathFile(string $path): bool
  {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
      Log::warning('Blueprint failed to create directory for file.', ['path' => $path, 'directory' => $directory]);

      return false;
    }

    $file = fopen($path, 'wb');
    if ($file === false) {
      Log::warning('Blueprint failed to create file.', ['path' => $path]);

      return false;
    }

    fclose($file);

    return true;
  }

  public function wipePath(string $path): bool
  {
    if (is_dir($path)) {
      $entries = scandir($path);
      if ($entries === false) {
        Log::warning('Blueprint failed to scan directory for deletion.', ['path' => $path]);

        return false;
      }

      $files = array_diff($entries, ['.', '..']);
      foreach ($files as $file) {
        if (!$this->wipePath($path . DIRECTORY_SEPARATOR . $file)) {
          return false;
        }
      }

      if (!rmdir($path)) {
        Log::warning('Blueprint failed to delete directory.', ['path' => $path]);

        return false;
      }

      return true;
    }

    if (is_file($path) || is_link($path)) {
      if (!unlink($path)) {
        Log::warning('Blueprint failed to delete file.', ['path' => $path]);

        return false;
      }

      return true;
    }

    return false;
  }

  private function parseYamlConfig(string $path): ?array
  {
    $contents = $this->readPathContents($path);
    if (!is_string($contents) || $contents === '') {
      return null;
    }

    try {
      $parsed = Yaml::parse($contents);
    } catch (\Throwable $exception) {
      Log::warning('Blueprint failed to parse YAML config file.', [
        'path' => $path,
        'message' => $exception->getMessage(),
      ]);

      return null;
    }

    if (!is_array($parsed)) {
      return null;
    }

    return $parsed;
  }

  private function readInstalledExtensionsFile(): ?string
  {
    return $this->readPathContents(base_path(self::INSTALLED_EXTENSIONS_PATH));
  }

  private function warnLegacyMethodUsage(string $method): void
  {
    if (isset(self::$warnedLegacyMethods[$method])) {
      return;
    }

    self::$warnedLegacyMethods[$method] = true;

    Log::warning('Blueprint deprecated helper method used.', [
      'method' => static::class . '::' . $method,
      'removal_target' => '2026-12',
    ]);
  }

  /**
   * Fetch a record from the database. (Data will be unserialized)
   *
   * @param string $table Database table
   * @param string $record Database record
   * @param mixed $default Optional. Returns this value when value is null.
   * @return mixed Database value
   *
   * [BlueprintExtensionLibrary documentation](https://blueprint.zip/docs/?page=documentation/$blueprint)
   */
  public function dbGet(string $table, string $record, mixed $default = null): mixed
  {
    $value = DB::table('settings')->where('key', $this->getRecordName($table, $record))->first();

    if (!$value) {
      return $default;
    }

    try {
      return unserialize($value->value);
    } catch (\Exception $e) {
      return $value->value;
    }
  }

  /**
   * Fetch many records from the database. (Data will be unserialized)
   *
   * @param string $table Database table
   * @param array $records Database records
   * @param mixed $default Optional. Returns this value when value is null.
   * @return array Database values as an associative array
   *
   * [BlueprintExtensionLibrary documentation](https://blueprint.zip/docs/?page=documentation/$blueprint)
   */
  public function dbGetMany(string $table, array $records = [], mixed $default = null): array
  {
    if (empty($records)) {
      $values = DB::table('settings')
        ->where('key', 'like', "$table::%")
        ->get();
    } else {
      $values = DB::table('settings')
        ->whereIn('key', array_map(fn($record) => $this->getRecordName($table, $record), $records))
        ->get();
    }

    if (empty($records)) {
      $records = $values->map(fn($value) => substr($value->key, strlen($table) + 2))->toArray();
    }

    $output = [];
    foreach ($records as $record) {
      $value = $values->firstWhere('key', $this->getRecordName($table, $record));

      if (!$value) {
        $output[$record] = $default;
        continue;
      }

      try {
        $output[$record] = unserialize($value->value);
      } catch (\Exception $e) {
        $output[$record] = $value->value;
      }
    }

    return $output;
  }

  /**
   * Set a database record. (Data will be serialized)
   *
   * @param string $table Database table
   * @param string $record Database record
   * @param string $value Value to store
   *
   * [BlueprintExtensionLibrary documentation](https://blueprint.zip/docs/?page=documentation/$blueprint)
   */
  public function dbSet(string $table, string $record, mixed $value): void
  {
    DB::table('settings')->updateOrInsert(
      ['key' => $this->getRecordName($table, $record)],
      ['value' => serialize($value)],
    );
  }

  /**
   * Set many database records. (Data will be serialized)
   *
   * @param string $table Database table
   * @param array $records Database records as an associative array
   *
   * [BlueprintExtensionLibrary documentation](https://blueprint.zip/docs/?page=documentation/$blueprint)
   */
  public function dbSetMany(string $table, array $records): void
  {
    $data = [];
    foreach ($records as $record => $value) {
      $data[] = [
        'key' => $this->getRecordName($table, $record),
        'value' => serialize($value),
      ];
    }

    DB::table('settings')->upsert($data, ['key'], ['value']);
  }

  /**
   * Delete/forget a database record.
   *
   * @param string $table Database table
   * @param string $record Database record
   * @return bool Whether there was a record to delete
   *
   * [BlueprintExtensionLibrary documentation](https://blueprint.zip/docs/?page=documentation/$blueprint)
   */
  public function dbForget(string $table, string $record): bool
  {
    return (bool) DB::table('settings')->where('key', $this->getRecordName($table, $record))->delete();
  }

  /**
   * Delete/forget many database records.
   *
   * @param string $table Database table
   * @param array $records Database records
   * @return bool Whether there was a record to delete
   *
   * [BlueprintExtensionLibrary documentation](https://blueprint.zip/docs/?page=documentation/$blueprint)
   */
  public function dbForgetMany(string $table, array $records): bool
  {
    return (bool) DB::table('settings')
      ->whereIn('key', array_map(fn($record) => $this->getRecordName($table, $record), $records))
      ->delete();
  }

  /**
   * Delete/forget all database records of table.
   *
   * @param string $table Database table
   * @return bool Whether there was a record to delete
   *
   * [BlueprintExtensionLibrary documentation](https://blueprint.zip/docs/?page=documentation/$blueprint)
   */
  public function dbForgetAll(string $table): bool
  {
    return (bool) DB::table('settings')->where('key', 'like', $this->getRecordName($table, '%'))->delete();
  }

  /**
   * (Deprecated) Read and returns the content of a given file.
   *
   * @deprecated beta-2025-09
   * @param string $path Path to file
   * @return string File contents or empty string if file does not exist or is not readable
   *
   * [BlueprintExtensionLibrary documentation](https://blueprint.zip/docs/?page=documentation/$blueprint)
   */
  public function fileRead(string $path): string
  {
    $this->warnLegacyMethodUsage(__FUNCTION__);

    return $this->readPathContents($path) ?? '';
  }

  /**
   * (Deprecated) Attempts to create a file.
   *
   * @deprecated beta-2025-09
   * @param string $path File name/path
   *
   * [BlueprintExtensionLibrary documentation](https://blueprint.zip/docs/?page=documentation/$blueprint)
   */
  public function fileMake(string $path): void
  {
    $this->warnLegacyMethodUsage(__FUNCTION__);

    $this->createPathFile($path);
  }

  /**
   * (Deprecated) Attempts to remove a file or directory.
   *
   * @deprecated beta-2025-09
   * @param string $path Path to file/directory
   * @return bool Whether the file/directory was removed
   *
   * [BlueprintExtensionLibrary documentation](https://blueprint.zip/docs/?page=documentation/$blueprint)
   */
  public function fileWipe(string $path): bool
  {
    $this->warnLegacyMethodUsage(__FUNCTION__);

    return $this->wipePath($path);
  }

  /**
   * Check if an extension is installed based on it's identifier.
   *
   * @param string $identifier Extension identifier
   * @return bool Whether the extension is installed
   *
   * [BlueprintExtensionLibrary documentation](https://blueprint.zip/docs/?page=documentation/$blueprint)
   */
  public function extension(string $identifier): bool
  {
    $installed = $this->readInstalledExtensionsFile();

    return is_string($installed) && str_contains($installed, "|$identifier,");
  }

  /**
   * Retrieves a list of installed extensions.
   *
   * This method reads a file containing a comma-separated list of installed
   * extensions, parses it into an array, and returns the result.
   *
   * @return array An array of installed extensions.
   */
  public function extensions(): array
  {
    $contents = $this->readInstalledExtensionsFile();
    if (!is_string($contents) || $contents === '') {
      return [];
    }

    $array = preg_replace('/[|]/', '', $contents);

    return array_values(array_filter(explode(',', $array), static fn($value) => $value !== ''));
  }

  /**
   * Retrieves the configuration for a specified extension.
   *
   * This method checks if the given extension exists and, if so, reads its
   * configuration file in YAML format. The configuration data is then filtered
   * to remove any empty or falsy keys.
   *
   * @param string $identifier Extension identifier to retrieve config from
   *
   * @return array|null The configuration array for the extension, or null if the extension does not exist.
   */
  public function extensionConfig(string $identifier): ?array
  {
    if (!$this->extension($identifier)) {
      return null;
    }

    $conf = $this->parseYamlConfig(base_path(".blueprint/extensions/$identifier/private/.store/conf.yml"));
    if (!is_array($conf)) {
      return null;
    }

    return array_filter($conf, fn($k) => !!$k);
  }

  /**
   * Returns a Collection containing all installed extensions's configs.
   *
   * @return Collection Collection of installed extensions's configs
   *
   * [BlueprintExtensionLibrary documentation](https://blueprint.zip/docs/?page=documentation/$blueprint)
   */
  public function extensionsConfigs(): Collection
  {
    $array = $this->extensions();
    $collection = new Collection();

    foreach ($array as $extension) {
      if (!$extension) {
        continue;
      }

      $conf = $this->parseYamlConfig(base_path(".blueprint/extensions/$extension/private/.store/conf.yml"));
      if (!is_array($conf)) {
        continue;
      }

      $collection->push(array_filter($conf, fn($k) => !!$k));
    }

    return $collection;
  }
}
