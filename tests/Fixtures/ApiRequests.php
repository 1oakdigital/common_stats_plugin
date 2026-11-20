<?php

namespace OneOakDigital\CommonStats\Tests\Fixtures;

use OneOakDigital\CommonStats\Stats\CountStat;

class ApiRequests extends CountStat
{
    protected string $name = 'api.requests';

    protected string $description = 'API requests';

    protected array $labels = ['route'];
}
