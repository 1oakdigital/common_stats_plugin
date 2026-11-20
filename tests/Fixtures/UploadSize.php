<?php

namespace OneOakDigital\CommonStats\Tests\Fixtures;

use OneOakDigital\CommonStats\Stats\MaxStat;

class UploadSize extends MaxStat
{
    protected string $name = 'upload.mb';

    protected string $description = 'Upload size';

    protected array $labels = [];

    protected bool $memory = false;
}
