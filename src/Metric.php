<?php

namespace OneOakDigital\CommonStats;

use InvalidArgumentException;

/**
 * A metric's name and labels, and the identifier they are stored under:
 * the name, then the labels sorted by key as ";key=value", e.g.
 * "mailer.api.messages;result=queued;site=dating1".
 */
final readonly class Metric
{
    /**
     * Lowercase letters, digits and . _ : - (up to 120).
     */
    public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9_.:-]{0,119}$/';

    /**
     * Lowercase letters, digits and _ (up to 32), not starting with a digit.
     */
    public const string LABEL_KEY_PATTERN = '/^[a-z_][a-z0-9_]{0,31}$/';

    /**
     * Letters, digits and _ . : @ / + - (1 to 64).
     */
    public const string LABEL_VALUE_PATTERN = '/^[A-Za-z0-9_.:@\/+-]{1,64}$/';

    /**
     * Longest identifier (stats_metrics.identifier).
     */
    public const int MAX_IDENTIFIER_LENGTH = 191;

    /**
     * @param  array<string, string>  $labels  sorted by key
     */
    private function __construct(public string $name, public array $labels) {}

    /**
     * @param  array<string, string|int|float|bool>  $labels
     *
     * @throws InvalidArgumentException when the name, a label or the identifier is invalid
     */
    public static function make(string $name, array $labels = []): self
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new InvalidArgumentException("Invalid metric name [{$name}]: use 1-120 of a-z 0-9 . _ : - starting with a letter or digit.");
        }

        $normalized = [];

        foreach ($labels as $key => $value) {
            $key = (string) $key;
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

            if (1 !== preg_match(self::LABEL_KEY_PATTERN, $key)) {
                throw new InvalidArgumentException("Invalid label [{$key}] of metric [{$name}]: use a-z 0-9 _ (up to 32), not starting with a digit.");
            }

            if (1 !== preg_match(self::LABEL_VALUE_PATTERN, $value)) {
                throw new InvalidArgumentException("Invalid value [{$value}] of label [{$key}] of metric [{$name}]: use 1-64 of A-Z a-z 0-9 _ . : @ / + -.");
            }

            $normalized[$key] = $value;
        }

        ksort($normalized);
        $metric = new self($name, $normalized);

        if (mb_strlen($metric->identifier()) > self::MAX_IDENTIFIER_LENGTH) {
            throw new InvalidArgumentException("Metric [{$metric->identifier()}] is longer than " . self::MAX_IDENTIFIER_LENGTH . ' characters.');
        }

        return $metric;
    }

    /**
     * The metric of a stored identifier.
     */
    public static function fromIdentifier(string $identifier): self
    {
        $parts = explode(';', $identifier);
        $name = array_shift($parts);
        $labels = [];

        foreach ($parts as $part) {
            [$key, $value] = explode('=', $part, 2) + [1 => ''];
            $labels[$key] = $value;
        }

        return new self($name, $labels);
    }

    public function identifier(): string
    {
        $identifier = $this->name;

        foreach ($this->labels as $key => $value) {
            $identifier .= ';' . $key . '=' . $value;
        }

        return $identifier;
    }
}
