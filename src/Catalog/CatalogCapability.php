<?php

declare(strict_types=1);

namespace Larena\Licensing\Catalog;

/**
 * One function of the shipped capability catalog ({@see CapabilityCatalog}): its registry id, the package that
 * declares it, its tier and trial from the Specs registry, and how a screen shows it (area, plain words, what it
 * needs, whether it is the system itself and always on).
 */
final readonly class CatalogCapability
{
    public const string TIER_FREE = 'free';

    /** @param list<string> $requires */
    public function __construct(
        public string $id,
        public string $package,
        public string $kind,
        public string $tier,
        public bool $trialEligible,
        public string $area,
        public bool $alwaysOn,
        public array $requires,
        public string $titleKey,
        public string $descriptionKey,
    ) {
    }

    /** Free by default: the catalog says so, no snapshot needed (owner decision 2026-11-25). */
    public function isFree(): bool
    {
        return $this->tier === self::TIER_FREE;
    }

    /** @param array<string, mixed> $entry */
    public static function fromArray(array $entry): self
    {
        $requires = is_array($entry['requires'] ?? null) ? array_values(array_filter($entry['requires'], 'is_string')) : [];

        return new self(
            (string) ($entry['id'] ?? ''),
            (string) ($entry['package'] ?? ''),
            (string) ($entry['kind'] ?? ''),
            (string) ($entry['tier'] ?? ''),
            ($entry['trial_eligible'] ?? false) === true,
            (string) ($entry['area'] ?? ''),
            ($entry['always_on'] ?? false) === true,
            $requires,
            (string) ($entry['title_key'] ?? ''),
            (string) ($entry['description_key'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'package' => $this->package,
            'kind' => $this->kind,
            'tier' => $this->tier,
            'trial_eligible' => $this->trialEligible,
            'area' => $this->area,
            'always_on' => $this->alwaysOn,
            'requires' => $this->requires,
            'title_key' => $this->titleKey,
            'description_key' => $this->descriptionKey,
        ];
    }
}
