<?php

namespace Drupal\microsoft_clarity;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Javascript local cache helper service class.
 */
class JavascriptLocalCache {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected FileSystem $fileSystem;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * A logger channel instance for microsoft_clarity.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The client for sending HTTP requests.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The state system.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * Clarity JS URL.
   *
   * @var string
   */
  protected string $clarityURL = 'https://www.clarity.ms/tag/';

  /**
   * Local cache path for downloaded JS.
   *
   * @var string
   */
  protected string $localJSCachePath = 'public://microsoft_clarity';

  /**
   * The construct.
   */
  public function __construct(ClientInterface $http_client, FileSystemInterface $file_system, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, StateInterface $state) {
    $this->httpClient = $http_client;
    $this->fileSystem = $file_system;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->logger = $logger_factory->get('microsoft_clarity');
  }

  /**
   * Download/Synchronize/Cache tracking code file locally.
   *
   * @param bool $synchronize
   *   Synchronize to local cache if remote file has changed.
   *
   * @return string
   *   The path to the local or remote tracking file.
   */
  public function fetchJavascript(bool $synchronize = FALSE) {
    $project_id = $this->configFactory->get('microsoft_clarity.settings')->get('project_id');
    $remote_url = $this->clarityURL . $project_id;

    // If cache is disabled, just return the URL of Clarity JS server.
    if (!$this->configFactory->get('microsoft_clarity.settings')->get('local_cache')) {
      return $remote_url;
    }

    $file_destination = $this->localJSCachePath . '/clarity.js';
    if (!file_exists($file_destination) || $synchronize) {
      // Download the latest tracking code.
      try {
        $data = (string) $this->httpClient->get($remote_url)->getBody();

        if (file_exists($file_destination)) {
          // Synchronize tracking code and replace local file if outdated.
          $data_hash_local = Crypt::hashBase64(file_get_contents($file_destination));
          $data_hash_remote = Crypt::hashBase64($data);
          // Check that the file's directory is writable.
          if ($data_hash_local != $data_hash_remote && $this->fileSystem->prepareDirectory($this->localJSCachePath)) {
            // Save updated tracking code file to disk.
            $this->fileSystem->saveData($data, $file_destination, FileSystemInterface::EXISTS_REPLACE);
            // Based on Drupal Core class AssetDumper.
            if (extension_loaded('zlib') && $this->configFactory->get('system.performance')->get('js.gzip')) {
              $this->fileSystem->saveData(gzencode($data, 9, FORCE_GZIP), $file_destination . '.gz', FileSystemInterface::EXISTS_REPLACE);
            }
            $this->logger->info('Locally cached tracking code file has been updated.');

            // Change query-strings on css/js files to enforce reload for all
            // users.
            _drupal_flush_css_js();
          }
        }
        else {
          // Check that the file's directory is writable.
          if ($this->fileSystem->prepareDirectory($this->localJSCachePath, FileSystemInterface::CREATE_DIRECTORY)) {
            // There is no need to flush JS here as core refreshes JS caches
            // automatically, if new files are added.
            $this->fileSystem->saveData($data, $file_destination, FileSystemInterface::EXISTS_REPLACE);
            // Based on Drupal Core class AssetDumper.
            if (extension_loaded('zlib') && $this->configFactory->get('system.performance')->get('js.gzip')) {
              $this->fileSystem->saveData(gzencode($data, 9, FORCE_GZIP), $file_destination . '.gz', FileSystemInterface::EXISTS_REPLACE);
            }
            $this->logger->info('Locally cached tracking code file has been saved.');
          }
        }
      }
      catch (RequestException $exception) {
        watchdog_exception('microsoft_clarity', $exception);
        return $remote_url;
      }
    }
    // Return the local JS file path.
    $query_string = '?' . ($this->state->get('system.css_js_query_string') ?: '0');
    return file_url_transform_relative(file_create_url($file_destination)) . $query_string;
  }

  /**
   * Delete cached files and directory.
   */
  public function clearJsCache(): void {
    if (is_dir($this->localJSCachePath)) {
      $this->fileSystem->deleteRecursive($this->localJSCachePath);

      // Change query-strings on css/js files to enforce reload for all users.
      _drupal_flush_css_js();

      $this->logger->info('Local Microsoft Clarity file cache has been purged.');
    }
  }

}
