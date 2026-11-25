<?php

declare(strict_types=1);

namespace Larena\Licensing\Catalog;

use JsonException;

/**
 * The capability catalog shipped with the package (`resources/catalog/capabilities.json`), generated from the Specs
 * capability registry by `composer run catalog:refresh`: sites have no Specs checkout, so the package carries what
 * the registry says. Paid or free is this data, not code (owner decision 2026-11-25).
 */
final readonly class CapabilityCatalog
{
    public const string ID_PREFIX = 'larena.capability.';

    public const string SHIPPED = __DIR__ . '/../../resources/catalog/capabilities.json';

    /**
     * @param array<string, CatalogCapability> $capabilities by id, sorted
     * @param list<string> $areas in the order screens show them
     */
    public function __construct(private array $capabilities, private array $areas = [])
    {
    }

    /** The catalog the package ships; empty when the file is missing or unreadable. */
    public static function shipped(): self
    {
        try {
            return self::fromFile(self::SHIPPED);
        } catch (\RuntimeException) {
            return new self([]);
        }
    }

    public static function fromFile(string $path): self
    {
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($bytes)) {
            throw new \RuntimeException('capability_catalog_missing');
        }
        try {
            $document = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new \RuntimeException('capability_catalog_malformed');
        }

        return self::fromArray(is_array($document) ? $document : []);
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        $capabilities = [];
        foreach (is_array($document['capabilities'] ?? null) ? $document['capabilities'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null) && $entry['id'] !== '') {
                $capabilities[$entry['id']] = CatalogCapability::fromArray($entry);
            }
        }
        ksort($capabilities, SORT_STRING);
        $areas = is_array($document['areas'] ?? null) ? array_values(array_filter($document['areas'], 'is_string')) : [];

        return new self($capabilities, $areas);
    }

    public function get(string $id): ?CatalogCapability
    {
        return $this->capabilities[$id] ?? null;
    }

    /** @return list<CatalogCapability> sorted by id */
    public function all(): array
    {
        return array_values($this->capabilities);
    }

    /** @return list<string> */
    public function areas(): array
    {
        return $this->areas;
    }

    /** @return list<string> the capabilities free for every site */
    public function freeIds(): array
    {
        return array_values(array_map(static fn (CatalogCapability $capability): string => $capability->id,
            array_filter($this->capabilities, static fn (CatalogCapability $capability): bool => $capability->isFree())));
    }

    public function isEmpty(): bool
    {
        return $this->capabilities === [];
    }

    /**
     * Everything switching `$id` on also needs, in the order to switch them on (needed ones first), without the
     * functions that are always on. Unknown ids are left out.
     *
     * @return list<string>
     */
    public function requiredFor(string $id): array
    {
        $order = [];
        $visit = function (string $current, array $path) use (&$visit, &$order): void {
            $capability = $this->capabilities[$current] ?? null;
            if ($capability === null || isset($path[$current])) {
                return;
            }
            $path[$current] = true;
            foreach ($capability->requires as $required) {
                $visit($required, $path);
            }
            if (!$capability->alwaysOn && !in_array($current, $order, true)) {
                $order[] = $current;
            }
        };
        $visit($id, []);

        return array_values(array_filter($order, static fn (string $item): bool => $item !== $id));
    }

    /**
     * The functions that need `$id`, directly or through another: what switching it off switches off too.
     *
     * @return list<string> sorted
     */
    public function dependantsOf(string $id): array
    {
        $found = [];
        foreach ($this->capabilities as $capability) {
            if ($capability->id !== $id && in_array($id, $this->requiredFor($capability->id), true)) {
                $found[] = $capability->id;
            }
        }
        sort($found, SORT_STRING);

        return $found;
    }
}
