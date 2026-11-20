<?php

namespace OneOakDigital\CommonStats\Tests\Fixtures;

use OneOakDigital\CommonStats\Stats\MinStat;

class QueueLag extends MinStat
{
    protected string $name = 'queue.lag_s';

    protected string $description = 'Queue lag';

    protected array $labels = [];
}
