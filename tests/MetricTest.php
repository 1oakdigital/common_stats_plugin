<?php

namespace OneOakDigital\CommonStats\Tests;

use InvalidArgumentException;
use OneOakDigital\CommonStats\Data\Metric;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MetricTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function invalidProvider(): array
    {
        return [
            'empty name' => ['', []],
            'uppercase name' => ['Signup', []],
            'pipe in name' => ['signup|done', []],
            'leading dot' => ['.signup', []],
            'long name' => [str_repeat('a', 121), []],
            'label key with a dash' => ['signup', ['the-site' => 'x']],
            'label key starting with a digit' => ['signup', ['1site' => 'x']],
            'empty label value' => ['signup', ['site' => '']],
            'label value with a semicolon' => ['signup', ['site' => 'a;b']],
            'label value with an equals sign' => ['signup', ['site' => 'a=b']],
            'identifier too long' => ['signup', ['a' => str_repeat('x', 64), 'b' => str_repeat('x', 64), 'c' => str_repeat('x', 64)]],
        ];
    }

    public function test_the_identifier_is_the_name_and_the_labels_sorted_by_key(): void
    {
        $metric = Metric::make('mailer.api.messages', ['site' => 'dating1', 'result' => 'queued', 'retried' => false, 'attempt' => 2]);

        $this->assertSame('mailer.api.messages;attempt=2;result=queued;retried=false;site=dating1', $metric->identifier());
        $this->assertEquals($metric, Metric::fromIdentifier($metric->identifier()));
        $this->assertSame('signup.completed', Metric::make('signup.completed')->identifier());
    }

    /**
     * @param  array<string, mixed>  $labels
     */
    #[DataProvider('invalidProvider')]
    public function test_an_invalid_metric_is_refused(string $name, array $labels): void
    {
        $this->expectException(InvalidArgumentException::class);

        Metric::make($name, $labels);
    }
}
