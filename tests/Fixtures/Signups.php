<?php

namespace OneOakDigital\CommonStats\Tests\Fixtures;

use OneOakDigital\CommonStats\Stats\CountStat;

class Signups extends CountStat
{
    protected string $name = 'signup.completed';

    protected string $description = 'Completed signups';

    protected array $labels = [];

    protected bool $memory = false;
}
