<?php

use AirSuite\Flysystem\OpenStack\OpenStackAdapter;
use AirSuite\Flysystem\OpenStack\OpenStackAdapterException;
use GuzzleHttp\Psr7\Stream;
use League\Flysystem\Config;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use OpenStack\ObjectStore\v1\Models\Container;
use OpenStack\ObjectStore\v1\Models\StorageObject;

class OpenStackAdapterTests extends MockeryTestCase
{
  public function getContainerMock($dataObject = null)
  {
    $container = Mockery::mock(Container::class);
    if ($dataObject !== null) {
      $container->shouldReceive('getObject')->andReturn($dataObject);
    }
    return $container;
  }

  public function getDataObjectMock($filename = null)
  {
    $dataObject = Mockery::mock(StorageObject::class);

    if ($filename === null) {
      // Returns a 'not found' StorageObject
      return $dataObject;
    }

    $dataObject->name = $filename;
    $dataObject->contentType = '; plain/text';
    $dataObject->lastModified = '2020-01-01';
    $dataObject->contentLength = '4';

    return $dataObject;
  }

  public function getStreamMock(&$resource)
  {
    $resource = tmpfile();
    $stream = Mockery::mock(Stream::class);
    $stream->shouldReceive('close');
    $stream->shouldReceive('rewind');
    $stream->shouldReceive('detach')->andReturn($resource);

    return $stream;
  }

  public function testRead()
  {
    $dataObject = $this->getDataObjectMock('filename.ext');
    $container = $this->getContainerMock($dataObject);

    $stream = $this->getStreamMock($resource);

    $dataObject->shouldReceive('download')->andReturn($stream);

    $adapter = new OpenStackAdapter($container);
    $this->assertIsArray($adapter->read('filename.ext'));
  }

  public function testReadStream()
  {
    $stream = $this->getStreamMock($resource);
    $dataObject = $this->getDataObjectMock('filename.ext');
    $container = $this->getContainerMock($dataObject);

    $dataObject->shouldReceive('download')->andReturn($stream);

    $adapter = new OpenStackAdapter($container);
    $response = $adapter->readStream('filename.ext');

    $this->assertIsArray($response);
    $this->assertEquals($resource, $response['stream']);
    fclose($resource);
  }

  public function testPrefixed()
  {
    $stream = $this->getStreamMock($resource);
    $dataObject = $this->getDataObjectMock('prefix/filename.ext');
    $container = $this->getContainerMock($dataObject);

    $dataObject->shouldReceive('download')->andReturn($stream);

    $adapter = new OpenStackAdapter($container, 'prefix');
    $this->assertIsArray($adapter->read('filename.ext'));
    fclose($resource);
  }

  public function testHas()
  {
    $container = $this->getContainerMock();
    $container->shouldReceive('objectExists')->andReturn(true);
    $adapter = new OpenStackAdapter($container);
    $this->assertTrue($adapter->has('filename.ext'));
  }

  public function testHasFail()
  {
    $container = $this->getContainerMock();
    $container
      ->shouldReceive('objectExists')
      ->andThrow(
        GuzzleHttp\Exception\BadResponseException::class,
        null,
        Mockery::mock(Psr\Http\Message\RequestInterface::class)
      );

    $adapter = new OpenStackAdapter($container);
    $this->assertFalse($adapter->has('filename.ext'));
  }

  public function testHasNotFound()
  {
    $container = $this->getContainerMock();
    $container->shouldReceive('objectExists')->andReturn(false);
    $adapter = new OpenStackAdapter($container);
    $this->assertFalse($adapter->has('filename.ext'));
  }

  public function testWrite()
  {
    $container = $this->getContainerMock();
    $dataObject = $this->getDataObjectMock('filename.ext');

    $container->shouldReceive('uploadObject')->with('filename.ext', 'content', []);
    $container->shouldReceive('createObject')->andReturn($dataObject);

    $adapter = new OpenStackAdapter($container);
    $this->assertIsArray($adapter->write('filename.ext', 'content', new Config()));
  }

  public function testWriteWithHeaders()
  {
    $container = $this->getContainerMock();
    $dataObject = $this->getDataObjectMock('filename.ext');
    $headers = ['custom' => 'headers'];

    $container->shouldReceive('uploadObject')->with('filename.ext', 'content', $headers);
    $container->shouldReceive('createObject')->andReturn($dataObject);

    $adapter = new OpenStackAdapter($container);
    $config = new Config(['headers' => $headers]);
    $this->assertIsArray($adapter->write('filename.ext', 'content', $config));
  }

  public function testWriteStream()
  {
    $container = $this->getContainerMock();
    $dataObject = $this->getDataObjectMock('filename.ext');

    $container->shouldReceive('uploadObject');
    $container->shouldReceive('createObject')->andReturn($dataObject);

    $adapter = new OpenStackAdapter($container);
    $config = new Config([]);

    $stream = tmpfile();
    fwrite($stream, 'something');

    $this->assertIsArray($adapter->writeStream('filename.ext', $stream, $config));

    fclose($stream);
  }

  public function testUpdateFail()
  {
    $dataObject = Mockery::mock(StorageObject::class);
    $container = $this->getContainerMock($dataObject);

    $dataObject->shouldReceive('delete')->andReturn(true);
    $container->shouldReceive('createObject')->andReturn($dataObject);

    $adapter = new OpenStackAdapter($container);
    $this->assertFalse($adapter->update('filename.ext', 'content', new Config()));
  }

  public function testUpdate()
  {
    $dataObject = $this->getDataObjectMock('filename.ext');
    $container = $this->getContainerMock($dataObject);

    $dataObject->shouldReceive('delete')->andReturn(true);
    $container->shouldReceive('createObject')->andReturn($dataObject);

    $adapter = new OpenStackAdapter($container);
    $this->assertIsArray($adapter->update('filename.ext', 'content', new Config()));
  }

  public function testUpdateStream()
  {
    $dataObject = $this->getDataObjectMock('filename.ext');
    $container = $this->getContainerMock($dataObject);

    $dataObject->shouldReceive('delete')->andReturn(true);
    $container->shouldReceive('createObject')->andReturn($dataObject);

    $adapter = new OpenStackAdapter($container);
    $resource = tmpfile();
    $this->assertIsArray($adapter->updateStream('filename.ext', $resource, new Config()));
    fclose($resource);
  }

  public function testCreateDir()
  {
    $container = $this->getContainerMock();
    $dataObject = $this->getDataObjectMock('dirname');

    $this->expectException(OpenStackAdapterException::class);

    $container
      ->shouldReceive('uploadObject')
      ->with('dirname', '', ['Content-Type' => 'application/directory'])
      ->andReturn($dataObject);

    $adapter = new OpenStackAdapter($container);
    $adapter->createDir('dirname', new Config());
  }

  public function getterProvider()
  {
    return [['getTimestamp'], ['getSize'], ['getMimetype']];
  }

  /**
   * @dataProvider  getterProvider
   */
  public function testGetters($function)
  {
    $dataObject = $this->getDataObjectMock('filename.ext');
    $container = $this->getContainerMock($dataObject);

    $container->shouldReceive('getPartialObject')->andReturn($dataObject);

    $adapter = new OpenStackAdapter($container);
    $this->assertIsArray($adapter->{$function}('filename.ext'));
  }

  public function testDelete()
  {
    $dataObject = $this->getDataObjectMock('filename.ext');
    $container = $this->getContainerMock($dataObject);

    $dataObject->shouldReceive('delete')->andReturn(true);

    $adapter = new OpenStackAdapter($container);
    $this->assertTrue($adapter->delete('filename.ext'));
  }

  public function testDeleteNotFound()
  {
    $this->expectException(GuzzleHttp\Exception\BadResponseException::class);

    $dataObject = $this->getDataObjectMock();
    $container = $this->getContainerMock($dataObject);

    $dataObject
      ->shouldReceive('delete')
      ->andThrow(
        GuzzleHttp\Exception\BadResponseException::class,
        null,
        Mockery::mock(Psr\Http\Message\RequestInterface::class)
      );

    $adapter = new OpenStackAdapter($container);
    $this->assertFalse($adapter->delete('filename.txt'));
  }

  public function testGetContainer()
  {
    $container = $this->getContainerMock();
    $adapter = new OpenStackAdapter($container);

    $this->assertEquals($container, $adapter->getContainer());
  }
}
