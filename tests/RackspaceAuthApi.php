<?php

namespace AirSuite\Flysystem\OpenStack\Test;

use OpenStack\Common\Api\ApiInterface;

class RackspaceAuthApi implements ApiInterface
{
  public function postToken(): array
  {
    return [
      'method' => 'POST',
      'path' => 'tokens',
      'params' => [
        'username' => [
          'type' => 'string',
          'required' => true,
          'path' => 'auth.RAX-KSKEY:apiKeyCredentials',
        ],
        'apiKey' => [
          'type' => 'string',
          'required' => true,
          'path' => 'auth.RAX-KSKEY:apiKeyCredentials',
        ],
        'tenantId' => [
          'type' => 'string',
          'path' => 'auth',
        ],
      ],
    ];
  }
}
