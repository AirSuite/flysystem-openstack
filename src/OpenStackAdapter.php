<?php

namespace AirSuite\Flysystem\OpenStack;

use GuzzleHttp\Psr7\Stream;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Adapter\Polyfill\StreamedCopyTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;
use OpenStack\ObjectStore\v1\Models\Container;
use OpenStack\ObjectStore\v1\Models\StorageObject;
use Throwable;

class OpenStackAdapter extends AbstractAdapter
{
  use StreamedCopyTrait;
  use NotSupportingVisibilityTrait;

  /**
   * @var Container
   */
  protected Container $container;

  /**
   * @var string
   */
  protected string $prefix;

  /**
   * Constructor.
   *
   * @param Container     $container
   * @param string | null $prefix
   */
  public function __construct(Container $container, $prefix = null)
  {
    $this->setPathPrefix($prefix);
    $this->container = $container;
  }

  /**
   * Get the container.
   *
   * @return Container
   */
  public function getContainer(): Container
  {
    return $this->container;
  }

  /**
   * Get an object.
   *
   * @param string $path
   *
   * @return StorageObject
   */
  protected function getObject($path): StorageObject
  {
    $location = $this->applyPathPrefix($path);
    return $this->container->getObject($location);
  }

  /**
   * Get the metadata of an object.
   *
   * @param string $path
   *
   * @return StorageObject
   */
  protected function getPartialObject($path): StorageObject
  {
    $location = $this->applyPathPrefix($path);
    return $this->container->getObject($location);
  }

  /**
   * {@inheritdoc}
   */
  public function write($path, $contents, Config $config)
  {
    $location = $this->applyPathPrefix($path);

    $object = ['name' => $location];

    if (is_resource($contents)) {
      $object['stream'] = new Stream($contents);
    } else {
      $object['contents'] = $contents;
    }

    $response = $this->container->createObject($object);

    return $this->normalizeObject($response);
  }

  /**
   * {@inheritdoc}
   */
  public function update($path, $contents, Config $config)
  {
    $this->delete($path);
    return $this->write($path, $contents, $config);
  }

  /**
   * {@inheritdoc}
   */
  public function rename($path, $newPath)
  {
    $object = $this->getObject($path);
    $newLocation = $this->applyPathPrefix($newPath);
    $destination = '/' . $this->container->name . '/' . ltrim($newLocation, '/');

    try {
      $object->copy(['destination' => $destination]);
    } catch (Throwable $_) {
      return false;
    }

    $object->delete();

    return true;
  }

  /**
   * {@inheritdoc}
   */
  public function delete($path)
  {
    $location = $this->applyPathPrefix($path);
    $this->container->getObject($location)->delete();

    return true;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteDir($dirname)
  {
    $location = $this->applyPathPrefix($dirname);

    /** @var StorageObject $object */
    foreach ($this->container->listObjects(['prefix' => $location]) as $object) {
      try {
        $object->delete();
      } catch (Throwable $_) {
        return false;
      }
    }

    return true;
  }

  /**
   * {@inheritdoc}
   * @throws OpenStackAdapterException
   */
  public function createDir($dirname, Config $config)
  {
    throw OpenStackAdapterException::whenTheUserAttemptsToCreateADirectory();
  }

  /**
   * {@inheritdoc}
   */
  public function writeStream($path, $resource, Config $config)
  {
    return $this->write($path, $resource, $config);
  }

  /**
   * {@inheritdoc}
   */
  public function updateStream($path, $resource, Config $config)
  {
    return $this->update($path, $resource, $config);
  }

  /**
   * {@inheritdoc}
   */
  public function has($path)
  {
    try {
      $location = $this->applyPathPrefix($path);
      $exists = $this->container->objectExists($location);
    } catch (Throwable $e) {
      return false;
    }

    return $exists;
  }

  /**
   * {@inheritdoc}
   */
  public function read($path)
  {
    $object = $this->getObject($path);
    $data = $this->normalizeObject($object);
    $data['contents'] = (string) $object->download();

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function readStream($path)
  {
    $object = $this->getObject($path);
    $data = $this->normalizeObject($object);
    $stream = $object->download();
    $stream->rewind();

    $data['stream'] = $stream->detach();

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function listContents($directory = '', $recursive = false)
  {
    $response = [];
    $marker = null;
    $location = $this->applyPathPrefix($directory);

    foreach ($this->container->listObjects(['prefix' => $location]) as $object) {
      $response[] = $object;
    }

    return Util::emulateDirectories(array_map([$this, 'normalizeObject'], $response));
  }

  protected function normalizeObject(StorageObject $object)
  {
    $name = $object->name;
    $name = $this->removePathPrefix($name);
    $mimetype = explode('; ', $object->contentType);

    if ($object->contentLength === null) {
      return false;
    }

    return [
      'type' => in_array('application/directory', $mimetype) ? 'dir' : 'file',
      'dirname' => Util::dirname($name),
      'path' => $name,
      'timestamp' => $object->lastModified,
      'mimetype' => reset($mimetype),
      'size' => $object->contentLength,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata($path)
  {
    $object = $this->getPartialObject($path);
    return $this->normalizeObject($object);
  }

  /**
   * {@inheritdoc}
   */
  public function getSize($path)
  {
    return $this->getMetadata($path);
  }

  /**
   * {@inheritdoc}
   */
  public function getMimetype($path)
  {
    return $this->getMetadata($path);
  }

  /**
   * {@inheritdoc}
   */
  public function getTimestamp($path)
  {
    return $this->getMetadata($path);
  }
}
