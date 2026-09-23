<?php

namespace FlareWeber\Deploy;

use FlareWeber\Models\Deployment;
use RuntimeException;

class DeploymentInProgressException extends RuntimeException
{
    public function __construct(public readonly Deployment $running)
    {
        parent::__construct('A deployment is already running for this site (#' . $running->id . ').');
    }
}
