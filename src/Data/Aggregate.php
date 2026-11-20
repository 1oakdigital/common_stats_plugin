<?php

namespace OneOakDigital\CommonStats\Data;

use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * A metric's aggregate over some values: the count, sum, average (the mean),
 * min or max by its type, and how many values were recorded.
 */
final readonly class Aggregate
{
    public function __construct(
        public string $identifier,
        public EStatType $type,
        public float $value,
        public int $samples,
    ) {}

    /**
     * The aggregates in a hash of the store: field "{type}|{identifier}" (an
     * average's is the running sum) and "{type}|{identifier}|n" (samples).
     * Keyed by "{type}|{identifier}".
     *
     * @param  array<string, string|int|float>  $fields
     * @return array<string, self>
     */
    public static function fromFields(array $fields): array
    {
        $parsed = [];

        foreach ($fields as $field => $value) {
            $field = (string) $field;
            $isSamples = str_ends_with($field, '|n');
            $key = $isSamples ? mb_substr($field, 0, -2) : $field;
            [$type, $identifier] = explode('|', $key, 2) + [1 => ''];
            $type = EStatType::tryFrom((int) $type);

            if (null === $type || '' === $identifier) {
                continue;
            }

            $parsed[$key] ??= ['type' => $type, 'identifier' => $identifier, 'value' => 0.0, 'samples' => 0];
            $parsed[$key][$isSamples ? 'samples' : 'value'] = $isSamples ? (int) $value : (float) $value;
        }

        $aggregates = [];

        foreach ($parsed as $key => $aggregate) {
            $value = EStatType::Average === $aggregate['type']
                ? $aggregate['value'] / max(1, $aggregate['samples'])
                : $aggregate['value'];

            $aggregates[$key] = new self($aggregate['identifier'], $aggregate['type'], $value, $aggregate['samples']);
        }

        return $aggregates;
    }

    /**
     * This aggregate and another of the same metric and type together.
     */
    public function merge(self $other): self
    {
        $samples = $this->samples + $other->samples;

        $value = match ($this->type) {
            EStatType::Count, EStatType::Sum => $this->value + $other->value,
            EStatType::Average => ($this->value * $this->samples + $other->value * $other->samples) / max(1, $samples),
            EStatType::Min => 0 === $this->samples ? $other->value : (0 === $other->samples ? $this->value : min($this->value, $other->value)),
            EStatType::Max => 0 === $this->samples ? $other->value : (0 === $other->samples ? $this->value : max($this->value, $other->value)),
        };

        return new self($this->identifier, $this->type, $value, $samples);
    }
}
