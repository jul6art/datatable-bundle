<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Nothing this bundle's JavaScript draws is a sentence written in the source.
 *
 * The API filter of the mobile sheet said "— Rechercher —" and its static filters "— Tous —" on
 * pages of every language, the actions column was headed "Actions" in all of them, the mobile card
 * list fell back to "No data" and an action without a label to "Action <type>": strings typed
 * into the controller, which no catalogue could reach. Every label the table draws
 * is a key of the `javascript` domain, and one the project has not translated yet falls back to
 * English through `_orEnglish()` — never to the raw key, never to French.
 *
 * ## What counts as drawn
 *
 * A string with at least two letters in a row, once its HTML tags are removed, that lands:
 *
 * - in a `placeholder`, `title`, `label`, `text`, `message` or `ariaLabel` property;
 * - in an assignment to `textContent`, `innerText`, `innerHTML`, `outerHTML`, `placeholder`,
 *   `title` or `ariaLabel`;
 * - in `setAttribute('title' | 'aria-label' | 'placeholder' | 'alt', …)`;
 * - in `alert()`, `confirm()` or `prompt()`;
 * - in the TEXT of a markup template — between tags, not inside one, where class names live.
 *
 * Exempt: the key of a `t()` / `trans()` call, and the English second argument of
 * `_orEnglish(this.t('…'), '…')`.
 *
 * ## Why a source guard
 *
 * This bundle ships no JavaScript runner (same reasoning as {@see Select2ClearSourceTest}). The
 * guard reads the source with a small lexer — comments, the three kinds of string, template
 * interpolations and regular-expression literals — because a regex over lines reads a class list
 * inside a template as a sentence, and a quote inside `/"/g` as the start of one. The provider
 * cases below prove it in both directions: it sees each kind of hard-coded string, and it lets
 * the translated forms through.
 */
#[CoversNothing]
final class HardCodedTextSourceTest extends TestCase
{
    /** `[` ends the look-back as well: `text: item[key || 'name']` is a lookup, not a label. */
    private const string SINK_PROPERTY = '/\b(?:placeholder|title|label|text|message|ariaLabel)\s*:[^,;{}\[]*$/';

    private const string SINK_ASSIGNMENT = '/\.(?:textContent|innerText|innerHTML|outerHTML|placeholder|title|ariaLabel)\s*=(?!=)[^;{}\[]*$/';

    private const string SINK_ATTRIBUTE = '/setAttribute\(\s*"(?:title|aria-label|placeholder|alt)"\s*,[^;{}]*$/';

    private const string SINK_DIALOG = '/(?:(?<![\w.$])|window\.)(?:alert|confirm|prompt)\s*\([^;{}]*$/';

    private const string TRANSLATION_KEY = '/(?<![\w$])(?:t|trans)\s*\(\s*$/';

    private const string ENGLISH_FALLBACK = '/_orEnglish\(\s*this\.t\("[\w.-]+"(?:\s*,\s*\{[^{}]*\})?\)\s*,\s*$/';

    /** A tag, its attribute values quoted — `data-action="submit->ui--modal#intercept"` holds a `>`. */
    private const string TAG = '/<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/';

    public function testNoAssetDrawsAHardCodedString(): void
    {
        $root = \dirname(__DIR__, 2).'/assets';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        $findings = [];
        $scanned = 0;
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'js' !== $file->getExtension()) {
                continue;
            }

            ++$scanned;
            foreach (self::findings((string) file_get_contents($file->getPathname())) as $finding) {
                $findings[] = substr($file->getPathname(), \strlen($root) + 1).':'.$finding;
            }
        }

        self::assertGreaterThan(10, $scanned, 'The guard must read the whole assets/ tree.');
        self::assertSame([], $findings, "These strings are drawn as written, in every language — make each a key of the `javascript` domain, with `_orEnglish()` for its English:\n  - ".implode("\n  - ", $findings));
    }

    #[DataProvider('hardCoded')]
    public function testTheGuardSeesAHardCodedString(string $source): void
    {
        self::assertNotSame([], self::findings($source));
    }

    #[DataProvider('translated')]
    public function testTheGuardLetsATranslatedStringThrough(string $source): void
    {
        self::assertSame([], self::findings($source));
    }

    /**
     * ⚠️ Same fallback as `select2Language()`: the translator returns the key itself for a
     * missing entry, and that key must never reach the screen.
     */
    public function testAnUntranslatedKeyFallsBackToEnglish(): void
    {
        self::assertMatchesRegularExpression(
            "/_orEnglish\\(translated, english\\) \\{\n\\s*return translated\\.startsWith\\('datatable\\.'\\) \\? english : translated;/",
            (string) file_get_contents(\dirname(__DIR__, 2).'/assets/controllers/datatable_controller.js'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hardCoded(): iterable
    {
        yield 'the 2.5.5 API filter placeholder' => ["placeholder: '— ' + (config?.placeholder || 'Rechercher') + ' —',"];
        yield 'a column title' => ["cols.push({ data: null, title: 'Actions' });"];
        yield 'textContent' => ["option.textContent = '— ' + (config.placeholder || 'Tous') + ' —';"];
        yield 'innerHTML with markup' => ["box.innerHTML = '<p>No data</p>';"];
        yield 'an aria-label attribute' => ["button.setAttribute('aria-label', 'Close');"];
        yield 'confirm()' => ["if (confirm('Are you sure?')) { go(); }"];
        yield 'window.alert()' => ['window.alert("Saved");'];
        yield 'text between tags of a template' => ['const html = `<div class="p-8"><span>No results</span></div>`;'];
        yield 'a literal fallback inside a template' => ["const html = `<p>\${this.getLanguageConfig().emptyTable || 'No data'}</p>`;"];
        yield 'a plain template in a title' => ['el.title = `Page ${n}`;'];
        yield 'after a regex holding a quote' => ["const s = x.replace(/\"/g, '&quot;');\nel.title = 'Edit';"];
        yield 'a template fallback for a label' => ['const item = { label: action.label || `Action ${action.type}` };'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function translated(): iterable
    {
        yield 'a key' => ["placeholder: this.t('datatable.views.name'),"];
        yield 'a key with its English' => ["placeholder: '— ' + (config?.placeholder || this._orEnglish(this.t('datatable.filter.search'), 'Search')) + ' —',"];
        yield 'a key inside a template' => ["const html = `<button aria-label=\"\${this._escAttr(this.t('datatable.columns.close'))}\">x</button>`;"];
        yield 'class names inside a tag' => ["const html = `<div class=\"dt-views-row\${active ? ' dt-views-row--active' : ''}\"><i class=\"fa-solid fa-check\"></i></div>`;"];
        yield 'an icon' => ["addon.innerHTML = '<i class=\"fa-regular fa-calendar\"></i>';"];
        yield 'a comment' => ["// el.title = 'Edit';\n/* placeholder: 'Rechercher' */"];
        yield 'a console message' => ["console.warn('[DataTable] AJAX error:', error);"];
        yield 'a header name' => ["headers: { 'Accept': 'application/ld+json' },"];
        yield 'a dash' => ["option.textContent = '— ' + label + ' —';"];
        yield 'a URL' => ['const url = `${prefix}/${id}`;'];
        yield 'a regex, then a key' => ["const s = x.replace(/\"/g, '&quot;');\nel.title = this.t('datatable.views.delete');"];
        yield 'a key with parameters and its English' => ["const item = { label: action.label || this._orEnglish(this.t('datatable.action.default', { '%type%': action.type }), `Action \${action.type}`) };"];
        yield 'a count handed to a translated string' => ["el.textContent = this.t('datatable.bulk.selected').replace('%count%', count);"];
        yield 'a lookup key' => ["const result = { text: item[select.dataset.filterTextKey || 'name'] ?? String(item.id) };"];
        yield 'a > inside a quoted attribute' => ["const html = `<form data-action=\"submit->ui--modal#intercept\" data-title=\"\${this._modalText(item, 'title')}\">\${button}</form>`;"];
    }

    /**
     * @return list<string> one "line N: text" per drawn string
     */
    private static function findings(string $source): array
    {
        $findings = [];
        $offset = 0;
        self::scanCode($source, $offset, false, false, $findings);

        return $findings;
    }

    /**
     * Reads code until the end of the source, or the `}` closing the interpolation it was called
     * for. `$context` is the code read so far in this scope, each string collapsed to its word
     * characters — enough for the sink patterns, too little to be fooled by a `;` inside a string.
     *
     * @param list<string> $findings
     */
    private static function scanCode(string $source, int &$offset, bool $inInterpolation, bool $inMarkupText, array &$findings): void
    {
        $context = '';
        $depth = 0;
        $length = \strlen($source);

        while ($offset < $length) {
            $char = $source[$offset];
            $next = $source[$offset + 1] ?? '';

            if ('/' === $char && '/' === $next) {
                $end = strpos($source, "\n", $offset);
                $offset = false === $end ? $length : $end;

                continue;
            }

            if ('/' === $char && '*' === $next) {
                $end = strpos($source, '*/', $offset + 2);
                $offset = false === $end ? $length : $end + 2;

                continue;
            }

            if ("'" === $char || '"' === $char) {
                $start = $offset;
                $text = self::readQuoted($source, $offset);
                self::judge($text, $context, $inMarkupText, false, self::line($source, $start), $findings);
                $context .= '"'.preg_replace('/[^\w.-]/', '', $text).'"';

                continue;
            }

            if ('`' === $char) {
                $start = $offset;
                ++$offset;
                $text = self::readTemplate($source, $offset, $findings);
                self::judge($text, $context, false, true, self::line($source, $start), $findings);
                $context .= '""';

                continue;
            }

            if ('/' === $char && self::startsRegex($context) && self::skipRegex($source, $offset)) {
                $context .= 'R';

                continue;
            }

            if ($inInterpolation) {
                if ('{' === $char) {
                    ++$depth;
                } elseif ('}' === $char) {
                    if (0 === $depth) {
                        ++$offset;

                        return;
                    }

                    --$depth;
                }
            }

            $context .= $char;
            ++$offset;
        }
    }

    /**
     * @param list<string> $findings
     */
    private static function judge(string $text, string $context, bool $inMarkupText, bool $template, int $line, array &$findings): void
    {
        $markup = $template && str_contains($text, '<');
        // Tags, entities, and the `%count%` a translated string is handed through `.replace()`.
        $visible = preg_replace([self::TAG, '/&#?\w+;/', '/%\w+%/'], '', $text) ?? $text;

        if (1 !== preg_match('/\p{L}{2,}/u', $visible)) {
            return;
        }

        $tail = substr($context, -200);

        if (1 === preg_match(self::TRANSLATION_KEY, $tail) || 1 === preg_match(self::ENGLISH_FALLBACK, $tail)) {
            return;
        }

        if ($markup || $inMarkupText || self::isSink($tail)) {
            $findings[] = \sprintf('line %d: %s', $line, trim(str_replace("\0", '${…}', $text)));
        }
    }

    private static function isSink(string $tail): bool
    {
        return array_any([self::SINK_PROPERTY, self::SINK_ASSIGNMENT, self::SINK_ATTRIBUTE, self::SINK_DIALOG], static fn (string $pattern): bool => 1 === preg_match($pattern, $tail));
    }

    private static function readQuoted(string $source, int &$offset): string
    {
        $quote = $source[$offset];
        $text = '';
        $length = \strlen($source);

        for (++$offset; $offset < $length; ++$offset) {
            $char = $source[$offset];

            if ('\\' === $char) {
                $text .= $source[++$offset] ?? '';

                continue;
            }

            if ($char === $quote) {
                ++$offset;

                break;
            }

            $text .= $char;
        }

        return $text;
    }

    /**
     * The template's text, each interpolation replaced by a NUL. An interpolation sitting between
     * tags is read as drawn text; one inside a tag (a class, an attribute) is not.
     *
     * @param list<string> $findings
     */
    private static function readTemplate(string $source, int &$offset, array &$findings): string
    {
        $text = '';
        $length = \strlen($source);

        while ($offset < $length) {
            $char = $source[$offset];

            if ('\\' === $char) {
                $text .= $source[$offset + 1] ?? '';
                $offset += 2;

                continue;
            }

            if ('`' === $char) {
                ++$offset;

                break;
            }

            if ('$' === $char && '{' === ($source[$offset + 1] ?? '')) {
                $offset += 2;
                $inMarkupText = str_contains($text, '<') && !str_contains((string) preg_replace(self::TAG, '', $text), '<');
                self::scanCode($source, $offset, true, $inMarkupText, $findings);
                $text .= "\0";

                continue;
            }

            $text .= $char;
            ++$offset;
        }

        return $text;
    }

    /**
     * A `/` opens a regular expression where an operand is expected: after an operator, an
     * opening bracket, a separator, or `return`.
     */
    private static function startsRegex(string $context): bool
    {
        $trimmed = rtrim($context);

        return '' === $trimmed
            || 1 === preg_match('/[(,=:\[!&|?{};+\-*%<>~^]$/', $trimmed)
            || 1 === preg_match('/(?<![\w$])(?:return|typeof|case|in|of)$/', $trimmed);
    }

    /**
     * Moves past a regular-expression literal and its flags. False — and the offset untouched —
     * when the line ends first: it was a division after all.
     */
    private static function skipRegex(string $source, int &$offset): bool
    {
        $length = \strlen($source);
        $inClass = false;

        for ($i = $offset + 1; $i < $length; ++$i) {
            $char = $source[$i];

            if ("\n" === $char) {
                return false;
            }

            if ('\\' === $char) {
                ++$i;

                continue;
            }

            if ('[' === $char) {
                $inClass = true;
            } elseif (']' === $char) {
                $inClass = false;
            } elseif ('/' === $char && !$inClass) {
                $offset = $i + 1;
                while ($offset < $length && ctype_alpha($source[$offset])) {
                    ++$offset;
                }

                return true;
            }
        }

        return false;
    }

    private static function line(string $source, int $offset): int
    {
        return substr_count($source, "\n", 0, $offset) + 1;
    }
}
