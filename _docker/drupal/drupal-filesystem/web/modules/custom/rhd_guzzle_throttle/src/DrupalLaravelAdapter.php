<?php
/**
 * Created by PhpStorm.
 * User: jporter
 * Date: 6/4/18
 * Time: 5:00 PM
 */

namespace Drupal\rhd_guzzle_throttle;

use DateTime;
use function GuzzleHttp\Psr7\copy_to_string;
use function GuzzleHttp\Psr7\stream_for;
use hamburgscleanest\GuzzleAdvancedThrottle\Cache\Helpers\RequestHelper;
use hamburgscleanest\GuzzleAdvancedThrottle\Cache\Interfaces\StorageInterface;
use hamburgscleanest\GuzzleAdvancedThrottle\Exceptions\LaravelCacheConfigNotSetException;
use hamburgscleanest\GuzzleAdvancedThrottle\RequestInfo;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Config\Repository;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class DrupalLaravelAdapter implements StorageInterface {

  private const RSPNS_STORAGE_KEY = 'rspns';
  private const BDY_STORAGE_KEY = 'bdy';

  /** @var int */
  private const DEFAULT_TTL = 300;
  /** @var int Time To Live in minutes */
  private $_ttl;
  /** @var \Illuminate\Contracts\Cache\Store */
  private $store;

  /**
   * StorageInterface constructor.
   * @param Repository|null $config
   */
  public function __construct(?Repository $config = NULL) {
    if ($config === null || ($cacheConfig = $config->get('cache')) === null)
    {
      throw new LaravelCacheConfigNotSetException();
    }

    $this->_ttl = $config->get('ttl', self::DEFAULT_TTL);
    $conn = $config->get('cache')['options']['connection'];
    $table = $config->get('cache')['options']['table'];
    $this->store = new DatabaseStore($conn, $table);
  }

  /**
   * @param RequestInterface $request
   * @param ResponseInterface $response
   */
  public function saveResponse(RequestInterface $request,
    ResponseInterface $response): void {
    // We need to know when we cached this
    $currentTimeStamp = (new DateTime())->getTimestamp();
    $cachedResponse = $response->withAddedHeader('cached-datetime', $currentTimeStamp);

    // Resist the body
    $body = $response->getBody()->getContents();

    // Rewind if possible
    if ($response->getBody()->isSeekable()) {
      $response->getBody()->rewind();
    }

    $this->store->forever($this->_buildResponseKey($request), $cachedResponse);
    $this->store->forever($this->_buildBodyKey($request), $body);
  }

  /**
   * @param RequestInterface $request
   * @return ResponseInterface|null
   * @throws \Exception
   */
  public function getResponse(RequestInterface $request): ?ResponseInterface {
    $env = \Drupal::config('redhat_developers')->get('environment');
    $anHourAgoTimestamp = new DateTime('1 hour ago');

    /** @var ResponseInterface $response */
    $response = $this->store->get($this->_buildResponseKey($request));
    $body = $this->store->get($this->_buildBodyKey($request));

    if (isset($response) && isset($body)) {
      // Add the body to the request
      $response = $response->withBody(stream_for($body));

      // Remove if we're in prod and data is old, otherwise return cached copy
      if ($env === 'prod') {
        $cachedDateTime = (new DateTime())->setTimestamp($response->getHeader('cached-datetime')[0]);

        if ($cachedDateTime < $anHourAgoTimestamp) {
          $this->store->forget($this->_buildResponseKey($request));
          $this->store->forget($this->_buildBodyKey($request));
          return NULL;
        }
      } else {
        return $response;
      }
    }
    return NULL;
  }

  /**
   * @param string $host
   * @param string $key
   * @param int $requestCount
   * @param DateTime $expiresAt
   * @param int $remainingSeconds
   */
  public function save(string $host, string $key, int $requestCount,
    DateTime $expiresAt, int $remainingSeconds) : void {
    $this->store->put(
      $this->_buildKey($host, $key),
      RequestInfo::create($requestCount, $expiresAt->getTimestamp(), $remainingSeconds),
      $remainingSeconds / 60
    );
  }

  /**
   * @param string $host
   * @param string $key
   * @return RequestInfo|null
   */
  public function get(string $host, string $key): ?RequestInfo {
    /** @noinspection PhpIncompatibleReturnTypeInspection */
    return $this->store->get($this->_buildKey($host, $key));
  }

  /**
   * @param string $host
   * @param string $key
   * @return string
   */
  private function _buildKey(string $host, string $key) : string
  {
    return $host . '.' . $key;
  }

  /**
   * @param RequestInterface $request
   * @return string
   */
  private function _buildResponseKey(RequestInterface $request) : string
  {
    [$host, $path] = RequestHelper::getHostAndPath($request);

    return self::RSPNS_STORAGE_KEY . '.' . \sha1($host . '.' . $path . '.' . RequestHelper::getStorageKey($request));
  }

  /**
   * @param RequestInterface $request
   * @return string
   */
  private function _buildBodyKey(RequestInterface $request) : string
  {
    [$host, $path] = RequestHelper::getHostAndPath($request);

    return self::BDY_STORAGE_KEY . '.' . \sha1($host . '.' . $path . '.' . RequestHelper::getStorageKey($request));
  }
}