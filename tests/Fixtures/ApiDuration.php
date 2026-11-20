<?php

namespace OneOakDigital\CommonStats\Tests\Fixtures;

use OneOakDigital\CommonStats\Stats\AverageStat;

class ApiDuration extends AverageStat
{
    protected string $name = 'api.duration_ms';

    protected string $description = 'API request duration';

    protected array $labels = ['route'];

    protected bool $memory = true;
}
