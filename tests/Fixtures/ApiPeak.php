<?php

namespace OneOakDigital\CommonStats\Tests\Fixtures;

use OneOakDigital\CommonStats\Stats\MaxStat;

class ApiPeak extends MaxStat
{
    protected string $name = 'api.peak_ms';

    protected string $description = 'Slowest API request';

    protected array $labels = [];
}
