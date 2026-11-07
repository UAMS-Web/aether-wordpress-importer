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
 * An `<a name="...">` anchor counts as an `id` too, since a browser scrolls to one,
 * and the import keeps no `name` (#505).
 *
 * A link to a page this run did not import cannot be checked, and is skipped.
 */
class DroppedAnchorTracker
{
    /**
     * Each anchor seen in a page's source HTML, as the element the warning names, by
     * page key, then the `id` or `name` it carries.
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
     * Every link with a fragment, once per page and href: the page holding it, its
     * href, the target page key and the fragment.
     *
     * @var array<string, array{page_link: string, link: string, target: string, fragment: string}>
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
        if (\preg_match('/\s(?:id|name)\s*=|\shref\s*=\s*["\']?[^"\'\s>]*#/i', $html) !== 1) {
            return;
        }

        self::$pageLinks[$pageKey] ??= $pageLink;

        // Record each id this content keeps, wherever in the Bard content it sits
        foreach (self::keptIds($bardContent) as $id) {
            self::$keptIds[$pageKey][$id] = true;
        }

        // Parse the source HTML for its ids and links. LIBXML_PARSEHUGE is needed as
        // in ContentProcessor (wordpress-importer-archive#1273): past libxml's default
        // depth of 256 the parser stops reading, still reports success, and every id
        // and link after that point would go unrecorded
        $doc = new DOMDocument;
        $previous = \libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8"?><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_PARSEHUGE
        );
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);
        if (! $loaded) {
            return;
        }

        $xpath = new DOMXPath($doc);

        // Record each id in the source, and each `name` of an `<a>`, keeping the first
        // element seen for a value
        $elements = $xpath->query('//*[@id] | //a[@name]');
        foreach ($elements === false ? [] : $elements as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            foreach (['id', 'name'] as $attribute) {
                $value = \trim($element->getAttribute($attribute));
                if ($value !== '' && ($attribute === 'id' || $element->tagName === 'a')) {
                    self::$sourceIds[$pageKey][$value] ??= '<'.$element->tagName.' '.$attribute.'="'.$value.'">';
                }
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

            // An outer call's HTML holds its nested calls' HTML, so the same link on
            // the same page arrives more than once and is kept once
            self::$links[$pageLink."\0".$href] ??= [
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
            $element = self::$sourceIds[$target][$fragment] ?? null;

            // An id the target never carried, or kept somewhere, is not dropped
            if ($element === null || isset(self::$keptIds[$target][$fragment])) {
                continue;
            }

            ImportWarningManager::addWarning('content', 'links_to_dropped_ids', [
                'page_link' => $link['page_link'],
                'link' => $link['link'],
                'target_page' => self::$pageLinks[$target] ?? $target,
                'element' => $element,
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
     * The key a page is matched on: host without `www.`, any port other than 80 or
     * 443, path without a trailing slash, and query, with percent-encoding in upper
     * case.
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

        // A relative URL takes the base page's host and port, and its directory when
        // the path does not start at the root. A leading `www.` names the same site,
        // and a port other than the default names another (#505)
        $hostParts = isset($parts['host']) ? $parts : $baseParts;
        $host = \preg_replace('/^www\./', '', \strtolower($hostParts['host'] ?? '')) ?? '';
        $port = $hostParts['port'] ?? null;
        if (! \in_array($port, [null, 80, 443], true)) {
            $host .= ':'.$port;
        }

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

        // Percent-encoding that differs only in case names the same page, as
        // WordPress stores a slug's in lower case and a browser copies it in upper
        return \preg_replace_callback(
            '/%[0-9a-f]{2}/i',
            static fn (array $match): string => \strtoupper($match[0]),
            $host.\rtrim($path, '/').$query
        ) ?? '';
    }
}
