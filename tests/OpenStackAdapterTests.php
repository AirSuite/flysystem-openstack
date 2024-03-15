<?php

namespace AirSuite\Flysystem\OpenStack\Test;

use AirSuite\Flysystem\OpenStack\OpenStackAdapter;
use Exception;
use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use OpenStack\Common\Transport\Utils as TransportUtils;
use OpenStack\Identity\v2\Service as V2IdentityService;
use OpenStack\ObjectStore\v1\Models\Container;
use OpenStack\OpenStack;
use Throwable;

class OpenStackAdapterTests extends FilesystemAdapterTestCase
{
  protected static Container $container;

  /**
   * @return void
   * @throws Exception
   */
  protected static function getSwiftService(): void
  {
    if (isset(self::$container)) {
      return;
    }

    $baseUrl = TransportUtils::normalizeUrl(getenv('OPENSTACK_KEYSTONE'));
    $guzzle = new Client(['base_uri' => $baseUrl, 'handler' => HandlerStack::create()]);
    $idService = new V2IdentityService($guzzle, new RackspaceAuthApi());

    $authUrl = getenv('OPENSTACK_KEYSTONE');

    if (empty($authUrl)) {
      throw new Exception('OPENSTACK_KEYSTONE environment variable is not set');
    }

    $openstack = new OpenStack([
      'identityService' => $idService,
      'authUrl' => getenv('OPENSTACK_KEYSTONE'),
      'region' => getenv('OPENSTACK_REGION'),
      'username' => getenv('OPENSTACK_USERID'),
      'apiKey' => getenv('OPENSTACK_PASSWORD'),
      'tenantId' => getenv('OPENSTACK_TENANT_ID'),
    ]);

    $service = $openstack->objectStoreV1(['catalogName' => 'cloudFiles']);
    self::$container = $service->getContainer(getenv('OPENSTACK_CONTAINER'));
  }

  /**
   * @return FilesystemAdapter
   * @throws Exception
   */
  protected static function createFilesystemAdapter(): FilesystemAdapter
  {
    self::getSwiftService();

    return new OpenStackAdapter(self::$container, getenv('OPENSTACK_TEMP_URL_KEY'), 'adapter-testing');
  }

  /**
   * Adjusted test to account for OpenStack not having directories.
   * @test
   * @throws Throwable
   */
  public function listing_contents_shallow(): void
  {
    $this->runScenario(function () {
      $this->givenWeHaveAnExistingFile('some/0-path.txt', 'contents');
      $this->givenWeHaveAnExistingFile('some/1-path.txt', 'contents');
      $this->givenWeHaveAnExistingFile('some/2-nested/path.txt', 'contents');

      $listing = $this->adapter()->listContents('some', false);
      /** @var StorageAttributes[] $items */
      $items = iterator_to_array($listing);

      $this->assertInstanceOf(Generator::class, $listing);
      $this->assertContainsOnlyInstancesOf(StorageAttributes::class, $items);

      $this->assertCount(2, $items, $this->formatIncorrectListingCount($items));

      usort($items, fn($a, $b) => strcmp($a->path(), $b->path()));

      $this->assertEquals('some/0-path.txt', $items[0]->path());
      $this->assertEquals('some/1-path.txt', $items[1]->path());
    });
  }

  /**
   * Adjusted test to account for OpenStack not having directories.
   * @test
   * @throws Throwable
   */
  public function listing_contents_recursive(): void
  {
    $this->runScenario(function () {
      $adapter = $this->adapter();

      $adapter->write('path/file.txt', 'string', new Config());
      $adapter->write('path/sub/file2.txt', 'string', new Config());

      $listing = $adapter->listContents('', true);
      /** @var StorageAttributes[] $items */
      $items = iterator_to_array($listing);
      $this->assertCount(2, $items, $this->formatIncorrectListingCount($items));
    });
  }

  /**
   * @test
   */
  public function fetching_unknown_mime_type_of_a_file(): void
  {
    $this->markTestSkipped('This adapter always returns a mime-type.');
  }

  /**
   * Meaningless for OpenStack.
   * @return void
   */
  public function checking_if_a_directory_exists_after_creating_it(): void
  {
    $this->markTestSkipped('OpenStack does not have directories.');
  }

  /**
   * Meaningless for OpenStack.
   * @return void
   */
  public function creating_a_directory(): void
  {
    $this->markTestSkipped('OpenStack does not have directories.');
  }

  /**
   * Meaningless for OpenStack.
   * @return void
   */
  public function cannot_get_checksum_for_directory(): void
  {
    $this->markTestSkipped('OpenStack does not have directories.');
  }

  /**
   * @return void
   */
  public function generating_a_public_url(): void
  {
    $this->markTestSkipped('This test depends on the user having configured their OpenStack container to be public.');
  }

  /**
   * @throws FilesystemException
   */
  protected function tearDown(): void
  {
    $this->adapter()->deleteDirectory('/');
    sleep(1);
  }
}
