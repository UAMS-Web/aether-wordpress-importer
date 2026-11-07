<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reports a link whose fragment names an `id` the import dropped (#502).
 *
 * Bard keeps an element's `id` only where `BardTexstyleAttributeMapper` maps it, so
 * the `id` of a quote, an image or a wrapper, among others, is lost on import (#374).
 * A link aimed at one then lands at the top of its page. Each call to
 * `TextProcessor::processForBard()` records, for the page being imported, the ids in
 * its source HTML, the ids its Bard content keeps, and every link with a fragment.
 * A page reaches that method more than once (one call per block or field), so the
 * links are matched only once the run has imported everything, by
 * {@see self::reportLinksToDroppedIds()}, and an `id` counts as dropped only when no
 * call on its page kept it.
 *
 * A link to a page this run did not import cannot be checked, and is skipped.
 */
class DroppedAnchorTracker
{
    /**
     * The tag of each `id` seen in a page's source HTML, by page key, then `id`.
     *
     * @var array<string, array<string, string>>
     */
    private static array $sourceIds = [];

    /**
     * Each `id` a page's Bard content keeps, by page key, then `id`.
     *
     * @var array<string, array<string, true>>
     */
    private static array $keptIds = [];

    /**
     * The page link each page key was recorded from, for the warning.
     *
     * @var array<string, string>
     */
    private static array $pageLinks = [];

    /**
     * Every link with a fragment: the page holding it, its href, the target page key
     * and the fragment.
     *
     * @var list<array{page_link: string, link: string, target: string, fragment: string}>
     */
    private static array $links = [];

    /**
     * Forget everything recorded, at the start of a run.
     */
    public static function clear(): void
    {
        self::$sourceIds = [];
        self::$keptIds = [];
        self::$pageLinks = [];
        self::$links = [];
    }

    /**
     * Record the ids and fragment links of one piece of a page's content.
     *
     * Does nothing without a current page link, since the page is the key every
     * record is matched on.
     *
     * @param  string  $html  The source HTML handed to `processForBard()`
     * @param  array<int|string, mixed>  $bardContent  The Bard content it produced
     */
    public static function record(
        string $html,
        array $bardContent
    ): void {
        // Without a page, nothing recorded here could be matched to a link
        $pageLink = ProcessingContext::getWordPressPageLink();
        $pageKey = $pageLink === null ? null : self::pageKey($pageLink, $pageLink);
        if ($pageLink === null || $pageKey === null) {
            return;
        }

        // Most content carries neither an id nor a link with a fragment, so skip
        // parsing it. A bare `#` is no sign of one, since every numeric entity has it
        if (\preg_match('/\sid\s*=|\shref\s*=\s*["\']?[^"\'\s>]*#/i', $html) !== 1) {
            return;
        }

        self::$pageLinks[$pageKey] ??= $pageLink;

        // Record each id this content keeps, wherever in the Bard content it sits
        foreach (self::keptIds($bardContent) as $id) {
            self::$keptIds[$pageKey][$id] = true;
        }

        // Parse the source HTML for its ids and links
        $doc = new DOMDocument;
        $previous = \libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8"?><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);
        if (! $loaded) {
            return;
        }

        $xpath = new DOMXPath($doc);

        // Record the tag of each id in the source, keeping the first tag seen
        $elements = $xpath->query('//*[@id]');
        foreach ($elements === false ? [] : $elements as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $id = \trim($element->getAttribute('id'));
            if ($id !== '') {
                self::$sourceIds[$pageKey][$id] ??= $element->tagName;
            }
        }

        // Record each link that carries a fragment, with the page it points at
        $anchors = $xpath->query('//a[@href]');
        foreach ($anchors === false ? [] : $anchors as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $href = \trim($anchor->getAttribute('href'));
            $hashAt = \strpos($href, '#');
            if ($hashAt === false) {
                continue;
            }

            $fragment = \rawurldecode(\substr($href, $hashAt + 1));
            $target = self::pageKey(\substr($href, 0, $hashAt), $pageLink);
            if ($fragment === '' || $target === null) {
                continue;
            }

            self::$links[] = [
                'page_link' => $pageLink,
                'link' => $href,
                'target' => $target,
                'fragment' => $fragment,
            ];
        }

        // Release the parsed document before the next piece of content
        unset($doc, $xpath, $elements, $anchors);
    }

    /**
     * Warn for every recorded link whose fragment names an id its target page
     * carried in the source and kept nowhere in its Bard content.
     *
     * Call once the run has imported its content, before the warnings are shown.
     */
    public static function reportLinksToDroppedIds(): void
    {
        foreach (self::$links as $link) {
            $target = $link['target'];
            $fragment = $link['fragment'];
            $tag = self::$sourceIds[$target][$fragment] ?? null;

            // An id the target never carried, or kept somewhere, is not dropped
            if ($tag === null || isset(self::$keptIds[$target][$fragment])) {
                continue;
            }

            ImportWarningManager::addWarning('content', 'links_to_dropped_ids', [
                'page_link' => $link['page_link'],
                'link' => $link['link'],
                'target_page' => self::$pageLinks[$target] ?? $target,
                'element' => '<'.$tag.' id="'.$fragment.'">',
                'reason' => 'The link names an id the import dropped, so it lands at the top of the page.',
            ]);
        }
    }

    /**
     * Every string under an `id` key anywhere in the Bard content.
     *
     * A set's own `attrs.id` is a generated row id, which matches no source id, so
     * collecting it as well is harmless.
     *
     * @param  array<int|string, mixed>  $content  Bard content, or any part of it
     * @return list<string> The ids found
     */
    private static function keptIds(array $content): array
    {
        $ids = [];

        foreach ($content as $key => $value) {
            if ($key === 'id' && \is_string($value) && $value !== '') {
                $ids[] = $value;
            } elseif (\is_array($value)) {
                \array_push($ids, ...self::keptIds($value));
            }
        }

        return $ids;
    }

    /**
     * The key a page is matched on: host, path without a trailing slash, and query.
     *
     * An empty URL is the base page itself, as for a same-page `#fragment` link, and a
     * relative one is resolved against the base page's host and directory. A URL with
     * a scheme other than http or https names no page and returns null.
     *
     * @param  string  $url  The URL without its fragment
     * @param  string  $base  The link of the page the URL appears on
     * @return ?string The page key, or null when the URL names no page
     */
    private static function pageKey(string $url, string $base): ?string
    {
        // A same-page link points at the page holding it
        if ($url === '') {
            $url = $base;
        }

        $parts = \parse_url($url);
        $baseParts = \parse_url($base);
        if (! \is_array($parts) || ! \is_array($baseParts)) {
            return null;
        }

        // Only a web page can hold an anchor
        $scheme = \strtolower($parts['scheme'] ?? 'http');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        // A relative URL takes the base page's host, and its directory when the
        // path does not start at the root
        $host = \strtolower($parts['host'] ?? ($baseParts['host'] ?? ''));
        $path = $parts['path'] ?? '';
        if (! isset($parts['host']) && $path !== '' && ! \str_starts_with($path, '/')) {
            $basePath = $baseParts['path'] ?? '/';
            $path = \substr($basePath, 0, (int) \strrpos($basePath, '/') + 1).$path;
        }

        // A query on its own, as in `?page_id=5`, keeps the base page's path
        if (! isset($parts['host']) && $path === '' && isset($parts['query'])) {
            $path = $baseParts['path'] ?? '';
        }

        // Resolve `.` and `..` segments, as a browser does
        $segments = [];
        foreach (\explode('/', $path) as $segment) {
            if ($segment === '..') {
                \array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }

        $path = \implode('/', $segments);

        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $host.\rtrim($path, '/').$query;
    }
}
