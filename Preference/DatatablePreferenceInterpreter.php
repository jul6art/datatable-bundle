<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Preference;

/**
 * Reads whatever a browser sent and gives back something a table can be built from.
 *
 * The client posts its own state — which columns it shows and in what order, how it is sorted, the
 * views the user saved. None of it is trustworthy: it comes from a page the user can edit, it was
 * written by a version of the table that may no longer exist, and it is stored for months. So
 * every field is bounded here, and the response is always the sanitised truth rather than an echo
 * of the request — the client adopts what comes back instead of assuming its payload was kept.
 *
 * ## What this class deliberately does NOT validate
 *
 * **Whether a column key or a filter parameter actually exists.** One route serves every table in
 * the application, so at this point there is no way to know which columns `erp_product` has — the
 * `*DataTableConfigProvider` that knows lives behind the page, not behind this endpoint. The
 * vocabulary is therefore reconciled client-side, where the declared columns are already in hand:
 * an unknown key is dropped, a declared key missing from the preferences is appended. Validating
 * here would mean either a second registry to keep in sync with sixty providers, or a stored blob
 * that silently loses a column the day it is renamed.
 *
 * The consequence is worth stating plainly: this class guarantees the blob is *well formed and
 * bounded*, not that it is *meaningful*. Both halves are needed, and the other half is JavaScript.
 *
 * ## Why the bounds are constants and not configuration
 *
 * They exist to keep a row in a database small and a panel in a dropdown readable, not to express
 * a preference. A project that needed forty saved views on one table would have a product problem,
 * not a configuration problem.
 *
 * @phpstan-type DatatableSortPreference array{key: string, dir: 'asc'|'desc'}
 * @phpstan-type DatatableColumnPreference array{key: string, visible: bool}
 * @phpstan-type DatatableViewPreference array{id: string, name: string, filters: array<string, string|list<string>>, sort: DatatableSortPreference|null, default: bool}
 * @phpstan-type DatatablePreferences array{v: int, columns: list<DatatableColumnPreference>, sort: DatatableSortPreference|null, views: list<DatatableViewPreference>}
 */
final readonly class DatatablePreferenceInterpreter
{
    /**
     * Stamped on every blob written. It is not read yet — there is one shape — and it is written
     * from the first version precisely so that the day there are two, the old rows say which one
     * they are. A migration that has to guess is a migration that drops preferences.
     */
    public const int SCHEMA_VERSION = 1;

    /**
     * The hard ceiling on a stored blob. The bounds below make it hard to reach; this is what
     * makes it impossible, whatever combination of them a client finds.
     */
    public const int MAX_BYTES = 16384;

    /** More columns than any readable table, and more than any column picker can be scrolled. */
    public const int MAX_COLUMNS = 100;

    public const int MAX_VIEWS = 20;

    /** Long enough for `assignments.department.label`, short enough to index. */
    public const int MAX_KEY_LENGTH = 120;

    public const int MAX_NAME_LENGTH = 60;

    /** Filter parameters carried by one saved view. */
    public const int MAX_FILTERS = 40;

    /** Values of one multi-valued filter parameter (`status[]=a&status[]=b`). */
    public const int MAX_FILTER_VALUES = 20;

    public const int MAX_VALUE_LENGTH = 255;

    /**
     * Decodes what the store gave back. Anything unreadable — truncated JSON, a blob written by
     * something else, a `null` column — comes back as the empty preferences rather than an error:
     * a corrupt row must cost the user their column layout, not their access to the page.
     *
     * @return DatatablePreferences
     */
    public function decode(?string $json): array
    {
        if (null === $json || '' === trim($json)) {
            return $this->empty();
        }

        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->empty();
        }

        return $this->interpret($decoded);
    }

    /**
     * Turns an arbitrary decoded payload into the canonical shape.
     *
     * @return DatatablePreferences
     */
    public function interpret(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return $this->empty();
        }

        return [
            'v' => self::SCHEMA_VERSION,
            'columns' => $this->columns($raw['columns'] ?? null),
            'sort' => $this->sort($raw['sort'] ?? null),
            'views' => $this->views($raw['views'] ?? null),
        ];
    }

    /**
     * Encodes for storage, minus the schema stamp's only job being to survive: the blob is
     * shortened by dropping saved views from the end until it fits {@see MAX_BYTES}.
     *
     * Dropping from the end rather than refusing the whole save is the deliberate choice: a user
     * who has just named a view expects to see it, and the ones they created first are the ones
     * they have been using. Refusing would mean losing the column layout of the same save.
     *
     * @param DatatablePreferences $preferences
     */
    public function encode(array $preferences): string
    {
        $views = $preferences['views'];

        while (true) {
            $preferences['views'] = $views;
            $json = json_encode($preferences, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

            if (\strlen($json) <= self::MAX_BYTES || [] === $views) {
                return $json;
            }

            array_pop($views);
        }
    }

    /**
     * @return DatatablePreferences
     */
    public function empty(): array
    {
        return ['v' => self::SCHEMA_VERSION, 'columns' => [], 'sort' => null, 'views' => []];
    }

    /**
     * The array ORDER is the display order — there is no index to keep in sync, and none to get
     * wrong when a column is added between two saves.
     *
     * @return list<DatatableColumnPreference>
     */
    private function columns(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $columns = [];
        $seen = [];

        foreach ($raw as $entry) {
            if (!\is_array($entry) || \count($columns) >= self::MAX_COLUMNS) {
                continue;
            }

            $key = self::key($entry['key'] ?? null);
            if (null === $key || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $columns[] = ['key' => $key, 'visible' => false !== ($entry['visible'] ?? true)];
        }

        return $columns;
    }

    /**
     * @return DatatableSortPreference|null
     */
    private function sort(mixed $raw): ?array
    {
        if (!\is_array($raw)) {
            return null;
        }

        $key = self::key($raw['key'] ?? null);
        if (null === $key) {
            return null;
        }

        return ['key' => $key, 'dir' => 'desc' === ($raw['dir'] ?? null) ? 'desc' : 'asc'];
    }

    /**
     * @return list<DatatableViewPreference>
     */
    private function views(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $views = [];
        $ids = [];
        $defaultTaken = false;

        foreach ($raw as $entry) {
            if (!\is_array($entry) || \count($views) >= self::MAX_VIEWS) {
                continue;
            }

            $name = self::name($entry['name'] ?? null);
            if (null === $name) {
                continue;
            }

            // The id is derived from the name and never trusted: it is what the client uses as a
            // list key, so two views must not share one, and a client that sent `id` from an
            // earlier name would keep a stale one forever.
            $id = self::uniqueId($name, $ids);
            $ids[$id] = true;

            // At most one default. The first wins rather than the last, so a payload where a
            // client marked two is resolved the same way on every save.
            $isDefault = !$defaultTaken && true === ($entry['default'] ?? false);
            $defaultTaken = $defaultTaken || $isDefault;

            $views[] = [
                'id' => $id,
                'name' => $name,
                'filters' => $this->filters($entry['filters'] ?? null),
                'sort' => $this->sort($entry['sort'] ?? null),
                'default' => $isDefault,
            ];
        }

        return $views;
    }

    /**
     * A filter value is a string or a list of strings — the multi-valued form is in the contract
     * from the start (`?status[]=a&status[]=b`) even where a table only offers single-valued
     * filters, because widening it later would mean two shapes in storage.
     *
     * @return array<string, string|list<string>>
     */
    private function filters(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $filters = [];

        foreach ($raw as $rawParam => $value) {
            $param = self::key($rawParam);
            if (null === $param || \count($filters) >= self::MAX_FILTERS) {
                continue;
            }

            $normalized = \is_array($value) ? self::valueList($value) : self::value($value);
            if (null === $normalized || [] === $normalized) {
                continue;
            }

            $filters[$param] = $normalized;
        }

        return $filters;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<string>
     */
    private static function valueList(array $raw): array
    {
        $values = [];

        foreach ($raw as $item) {
            if (\count($values) >= self::MAX_FILTER_VALUES) {
                break;
            }

            $value = self::value($item);
            if (null !== $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private static function value(mixed $raw): ?string
    {
        if (\is_bool($raw)) {
            return $raw ? 'true' : 'false';
        }

        if (\is_int($raw) || \is_float($raw)) {
            $raw = (string) $raw;
        }

        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        return self::truncate($raw, self::MAX_VALUE_LENGTH);
    }

    /**
     * A column key or a filter parameter: printable, no control characters, bounded. The brackets
     * of `createdAt[after]` and the dots of `assignments.department.label` are part of the
     * vocabulary, so the guard is on shape and length, not on an allowlist of characters.
     */
    private static function key(mixed $raw): ?string
    {
        if (!\is_string($raw)) {
            return null;
        }

        $key = trim($raw);

        if ('' === $key || \strlen($key) > self::MAX_KEY_LENGTH || 1 === preg_match('/[\x00-\x1F\x7F]/', $key)) {
            return null;
        }

        return $key;
    }

    private static function name(mixed $raw): ?string
    {
        if (!\is_string($raw)) {
            return null;
        }

        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $raw));

        return '' === $name ? null : self::truncate($name, self::MAX_NAME_LENGTH);
    }

    /**
     * Cuts to a number of CHARACTERS, on a character boundary, without `mbstring`.
     *
     * `substr()` on a byte count splits a multi-byte character in half, and the half that lands in
     * the database is invalid UTF-8 — `json_encode()` then fails on the *next* read, on a row
     * nothing else can explain. The `u` modifier makes PCRE count characters instead. A string
     * that is not valid UTF-8 to begin with makes the match fail, and it is dropped rather than
     * stored: garbage in, nothing out.
     */
    private static function truncate(string $value, int $length): ?string
    {
        if (1 !== preg_match(\sprintf('/^.{0,%d}/us', $length), $value, $matches)) {
            return null;
        }

        return '' === $matches[0] ? null : $matches[0];
    }

    /**
     * @param array<string, true> $taken
     */
    private static function uniqueId(string $name, array $taken): string
    {
        $base = self::slug($name);
        $id = $base;
        $suffix = 1;

        while (isset($taken[$id])) {
            $id = $base.'-'.++$suffix;
        }

        return $id;
    }

    /**
     * The same derivation the JavaScript applies when it adds a view optimistically, so the row it
     * draws before the response lands already carries the id the server will hand back.
     */
    private static function slug(string $name): string
    {
        // `strtolower` + a non-alphanumeric squash, and no transliteration: this has to produce
        // the SAME id as the two lines of JavaScript that add a view optimistically, and those
        // have no transliterator. An accent therefore becomes a dash on both sides, which is
        // invisible — the id is a list key, never shown.
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

        // A name written entirely outside the Latin alphabet slugs to nothing; a stable fallback
        // keeps it addressable instead of colliding with every other such name.
        return '' === $slug ? 'view-'.substr(md5($name), 0, 8) : substr($slug, 0, 40);
    }
}
