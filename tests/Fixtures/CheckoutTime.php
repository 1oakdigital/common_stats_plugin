<?php

namespace OneOakDigital\CommonStats\Tests\Fixtures;

use OneOakDigital\CommonStats\Stats\AverageStat;

class CheckoutTime extends AverageStat
{
    protected string $name = 'checkout.ms';

    protected string $description = 'Checkout time';

    protected array $labels = [];

    protected bool $memory = false;
}
