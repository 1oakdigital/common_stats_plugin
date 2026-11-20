<?php

namespace OneOakDigital\CommonStats\Tests\Fixtures;

use OneOakDigital\CommonStats\Stats\SumStat;

class Revenue extends SumStat
{
    protected string $name = 'revenue.eur';

    protected string $description = 'Revenue in EUR';

    protected array $labels = [];
}
