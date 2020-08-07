<?php

namespace AirSuite\Flysystem\OpenStack;

use Exception;

class OpenStackAdapterException extends Exception
{
  static function whenTheUserAttemptsToCreateADirectory()
  {
    return new static('Directories cannot be explicitly created for Object Storage. Store files directly.');
  }
}
