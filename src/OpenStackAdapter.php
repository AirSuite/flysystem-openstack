<?php

namespace AirSuite\Flysystem\OpenStack;

use DateTime;
use DateTimeInterface;
use Exception;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\StreamWrapper;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToGeneratePublicUrl;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;
use OpenStack\Common\Error\BadResponseError;
use OpenStack\ObjectStore\v1\Models\Container;
use OpenStack\ObjectStore\v1\Models\StorageObject;
use Throwable;

class OpenStackAdapter implements FilesystemAdapter, TemporaryUrlGenerator, PublicUrlGenerator, ChecksumProvider
{
  const VISIBILITY_HEADER = 'X-File-Visibility';

  private Container $container;
  private PathPrefixer $prefixer;
  private string $tempUrlKey;

  /**
   * Constructor.
   *
   * @param Container $container
   * @param string $tempUrlKey
   * @param string | null $prefix
   */
  public function __construct(Container $container, string $tempUrlKey, string $prefix = null)
  {
    $this->prefixer = new PathPrefixer($prefix);
    $this->container = $container;
    $this->tempUrlKey = $tempUrlKey;
  }

  /**
   * @throws BadResponseError
   */
  public function fileExists(string $path): bool
  {
    $path = $this->prefixer->prefixPath($path);
    return $this->container->objectExists($path);
  }

  /**
   * This is largely meaningless in the context of OpenStack Object storage.
   * It's not hierarchical.
   * {@inheritdoc}
   */
  public function directoryExists(string $path): bool
  {
    $location = $this->prefixer->prefixPath($path);
    $gen = $this->container->listObjects(['prefix' => $location]);
    return !empty($gen->current());
  }

  /**
   * Recursively deletes all objects with a certain prefix.
   * {@inheritdoc}
   */
  public function deleteDirectory(string $path): void
  {
    $location = $this->prefixer->prefixPath($path);
    foreach ($this->container->listObjects(['prefix' => $location]) as $object) {
      $object->delete();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createDirectory(string $path, Config $config): void
  {
    // OpenStack doesn't have directories, so this is a no-op.
  }

  /**
   * {@inheritdoc}
   */
  public function setVisibility(string $path, string $visibility): void
  {
    try {
      $object = $this->getObject($path);
      $meta = $object->getMetadata();
      $meta[self::VISIBILITY_HEADER] = $visibility;
      $object->resetMetadata($meta);
    } catch (Throwable $t) {
      throw new UnableToSetVisibility("Unable to set visibility for $path", 0, $t);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function visibility(string $path): FileAttributes
  {
    try {
      $object = $this->getObject($path);
      $meta = $object->getMetadata();

      if (!array_key_exists(self::VISIBILITY_HEADER, $meta)) {
        throw new Exception(self::VISIBILITY_HEADER . ' not found in metadata.');
      }

      return new FileAttributes($object->name, null, $meta[self::VISIBILITY_HEADER]);
    } catch (Throwable $t) {
      throw new UnableToRetrieveMetadata("Unable to retrieve visibility for $path", 0, $t);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function mimeType(string $path): FileAttributes
  {
    try {
      $object = $this->getObject($path);
      $object->retrieve();

      if ($object->contentType === null) {
        throw new Exception('contentType is null.');
      }

      return new FileAttributes($object->name, null, null, null, $object->contentType);
    } catch (Throwable $t) {
      throw new UnableToRetrieveMetadata("Unable to retrieve mime type for $path", 0, $t);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function lastModified(string $path): FileAttributes
  {
    try {
      $object = $this->getObject($path);
      $object->retrieve();

      $date = $object->lastModified;
      if (is_string($date)) {
        $date = new DateTime($date);
      }

      return new FileAttributes($object->name, null, null, $date->getTimestamp());
    } catch (Throwable $t) {
      throw new UnableToRetrieveMetadata("Unable to retrieve last modified for $path", 0, $t);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fileSize(string $path): FileAttributes
  {
    try {
      $object = $this->getObject($path);
      $object->retrieve();

      if ($object->contentLength === null) {
        throw new Exception('contentLength is null.');
      }

      return new FileAttributes($object->name, $object->contentLength);
    } catch (Throwable $t) {
      throw new UnableToRetrieveMetadata("Unable to retrieve file size for $path", 0, $t);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function move(string $source, string $destination, Config $config): void
  {
    try {
      $object = $this->getObject($source);
      $destinationLocation = $this->makeCopyDestination($destination);
      $object->copy(['destination' => $destinationLocation]);
      $object->delete();
    } catch (Throwable $t) {
      throw new UnableToMoveFile("Error moving $source to $destination", 0, $t);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function copy(string $source, string $destination, Config $config): void
  {
    try {
      $object = $this->getObject($source);
      $destinationLocation = $this->makeCopyDestination($destination);
      $object->copy(['destination' => $destinationLocation]);
    } catch (Throwable $t) {
      throw new UnableToCopyFile("Error copying $source to $destination", 0, $t);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function write(string $path, string $contents, Config $config): void
  {
    $location = $this->prefixer->prefixPath($path);
    try {
      $visibility = $config->get('visibility');
      $opt = ['name' => $location, 'content' => $contents];

      if ($visibility) {
        $opt['metadata'] = [self::VISIBILITY_HEADER => $visibility];
      }

      $this->container->createObject($opt);
    } catch (Throwable $t) {
      throw new UnableToWriteFile("Error writing to $location", 0, $t);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function writeStream(string $path, $contents, Config $config): void
  {
    $location = $this->prefixer->prefixPath($path);

    try {
      $stream = new Stream($contents);
      $this->container->createObject(['name' => $location, 'stream' => $stream]);
    } catch (Throwable $t) {
      throw new UnableToWriteFile("Error writing to $location", 0, $t);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function read(string $path): string
  {
    try {
      $object = $this->getObject($path);
      return $object->download();
    } catch (Throwable $t) {
      throw new UnableToReadFile("Error reading $path", 0, $t);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function readStream(string $path)
  {
    try {
      $object = $this->getObject($path);
      $stream = $object->download(['stream' => true]);

      return StreamWrapper::getResource($stream);
    } catch (Throwable $t) {
      throw new UnableToReadFile("Error reading $path", 0, $t);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $path): void
  {
    try {
      $object = $this->getObject($path);
      $object->delete();
    } catch (Throwable $t) {
      if ($t instanceof BadResponseError && $t->getResponse()->getStatusCode() === 404) {
        // Already gone
        return;
      }

      throw new UnableToDeleteFile("Error deleting $path", 0, $t);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function listContents(string $path, bool $deep): iterable
  {
    $location = $this->prefixer->prefixPath($path);
    $objects = $this->container->listObjects(['prefix' => $location]);
    foreach ($objects as $object) {
      if (!$deep) {
        $dir = dirname($object->name);
        if ($dir !== $location) {
          continue;
        }
      }

      yield new FileAttributes(
        $this->prefixer->stripPrefix($object->name),
        $object->contentLength,
        null,
        $object->lastModified->getTimestamp(),
        $object->contentType,
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function temporaryUrl(string $path, DateTimeInterface $expiresAt, Config $config): string
  {
    $object = $this->getObject($path);
    $pubUrl = $object->getPublicUri();

    [$base_url, $object_path] = explode('/v1/', $pubUrl);
    $object_path = "/v1/$object_path";
    $expires = $expiresAt->getTimestamp();
    $hmac_body = "GET\n$expires\n$object_path";
    $sig = hash_hmac('sha256', $hmac_body, $this->tempUrlKey);
    return "$base_url$object_path?temp_url_sig=$sig&temp_url_expires=$expires";
  }

  /**
   * {@inheritdoc}
   */
  public function publicUrl(string $path, Config $config): string
  {
    try {
      $object = $this->getObject($path);
      return $object->getPublicUri();
    } catch (Throwable $t) {
      throw new UnableToGeneratePublicUrl("Error generating public URL for $path", 0, $t);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checksum(string $path, Config $config): string
  {
    try {
      $object = $this->getObject($path);
      $object->retrieve();

      return $object->hash;
    } catch (Throwable $t) {
      throw new UnableToProvideChecksum("Error retrieving checksum for $path", 0, $t);
    }
  }

  /**
   * @param string $path
   * @return StorageObject
   */
  public function getObject(string $path): StorageObject
  {
    $location = $this->prefixer->prefixPath($path);
    return $this->container->getObject($location);
  }

  /**
   * @param string $destination
   * @return string
   */
  private function makeCopyDestination(string $destination): string
  {
    $destinationLocation = $this->prefixer->prefixPath($destination);
    return '/' . $this->container->name . '/' . ltrim($destinationLocation, '/');
  }

  private function signUrl(StorageObject $object, int $getTimestamp)
  {
    $method = 'GET';
    $url = $argv[2];
    $seconds = $argv[3];
    $key = $argv[4];
    $method = strtoupper($method);
    [$base_url, $object_path] = split('/v1/', $url);
    $object_path = "/v1/$object_path";
    $seconds = (int) $seconds;
    $expires = (int) (time() + $seconds);
    $hmac_body = "$method\n$expires\n$object_path";
    $sig = hash_hmac('sha256', $hmac_body, $key);
    echo "$base_url$object_path?" . "temp_url_sig=$sig&temp_url_expires=$expires";
  }
}
