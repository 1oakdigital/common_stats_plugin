<?php

namespace OneOakDigital\CommonStats\Tests\Fixtures;

use OneOakDigital\CommonStats\Stats\CountStat;

class MessagesReceived extends CountStat
{
    protected string $name = 'mailer.api.messages';

    protected string $description = 'Emails received by the API';

    protected array $labels = ['site', 'result'];
}
