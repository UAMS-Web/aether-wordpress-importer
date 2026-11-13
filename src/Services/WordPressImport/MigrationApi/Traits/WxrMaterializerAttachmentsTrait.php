<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\Traits;

use SimpleXMLElement;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\AttachmentReferenceCollector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Reads a scoped run's attachments for `WxrMaterializer`.
 *
 * Reads the attachments a scoped run's items reference, falls back to every attachment
 * when narrowing cannot be shown to lose nothing, and records how the read was resolved.
 */
trait WxrMaterializerAttachmentsTrait
{
    /**
     * How the last scoped run read its attachments, or null when it was not scoped.
     *
     * The same block the manifest records under `scope.attachments`.
     *
     * @return array{resolution: string, reason: string, ids_requested: int, paths_requested: int, records: int, unresolved_image_count: int, unresolved_images: list<string>, unresolved_link_count: int, unresolved_links: list<string>, unserved_ids: list<int>}|null
     */
    public function attachmentResolution(): ?array
    {
        return $this->attachmentResolution;
    }

    /**
     * Read a scoped run's attachments by what its items reference (wordpress-importer-archive#1175).
     *
     * **Narrowing is kept only when it can be shown to lose nothing.** After the referenced
     * ids and files are read, two checks decide it, and either one failing widens the read to
     * every attachment, as an unscoped run does:
     *
     *   images  every same-site upload image URL must resolve to a record read, through
     *           `XpathHelper::findAttachmentByUrl()`, the matcher the image pipeline itself
     *           uses. The pipeline prefers that match over a `wp-image-N` class, so an image
     *           it cannot resolve here could resolve to a different record in a full read
     *   links   every same-site permalink must match an item read, attachments included, by
     *           its link or, as `LinkProcessor`'s structure match does for a draft, by the
     *           slug its last segment names. An attachment's own page is a permalink like any
     *           page's, and cannot be picked out without the attachment items
     *
     * A read is also widened when the references would cost as many requests as reading
     * every attachment, which is correct by construction and no dearer.
     *
     * A reference in a named shape whose attachment the site does not serve is reported,
     * because narrowing is what would otherwise make it silent.
     *
     * @param  Client  $client  A client addressed to the site's own REST root
     * @param  AttachmentReferenceCollector  $collector  The references the run's items carry
     * @param  array<string, true>  $itemLinks  Normalized permalinks of every item read so far
     * @param  array<string, true>  $itemSlugs  Lowercased slugs of every item read so far
     * @param  array<string, scalar>  $query  The query every post read carries
     * @param  array<array-key, mixed>  $channel  The site's discovery payload
     * @return list<mixed> The attachment records to write
     */
    private function attachmentsByReference(
        Client $client,
        AttachmentReferenceCollector $collector,
        array $itemLinks,
        array $itemSlugs,
        array $query,
        array $channel
    ): array {
        $ids = $collector->ids();
        $paths = $collector->paths();
        $chunkSize = \min(self::REFERENCE_CHUNK, $client->recordsPerPage());
        $requests = [
            ['include', $this->referenceChunks('include', $ids, $chunkSize)],
            ['file', $this->referenceChunks('file', $paths, $chunkSize)],
        ];

        // One request per chunk, against one per page for the whole collection. The count is
        // a table inventory and can overstate what is served, so it only ever widens early.
        $narrowRequests = \count($requests[0][1]) + \count($requests[1][1]);
        $counts = $channel['counts'] ?? null;
        $byPostType = \is_array($counts) ? ($counts['by_post_type'] ?? null) : null;
        // A numeric string divides as the number it holds, so the cast only keeps the type.
        // @pest-mutate-ignore: RemoveIntegerCast
        $total = \is_array($byPostType) && \is_numeric($byPostType['attachment'] ?? null)
            ? (int) $byPostType['attachment']
            : null;

        // Comparing with the float ceil() answers is the same comparison, so the cast only
        // keeps the type.
        // @pest-mutate-ignore: RemoveIntegerCast
        if ($total !== null && $narrowRequests > 0 && $narrowRequests >= (int) \ceil($total / $client->recordsPerPage())) {
            return $this->allAttachments($client, $query, 'cheaper', \count($ids), \count($paths), [], []);
        }

        // Read what the items reference, by id and by file. A record both select is kept once.
        $records = [];

        foreach ($requests as [$parameter, $chunks]) {
            foreach ($chunks as $chunk) {
                foreach ($client->paginate('posts/attachment', null, \array_merge($query, [$parameter => $chunk])) as $record) {
                    // A value that is not an array answers null for its id, which is not a
                    // number either way; and a numeric string key is stored as that integer.
                    // @pest-mutate-ignore: BooleanAndToBooleanOr, RemoveIntegerCast
                    if (\is_array($record) && \is_numeric($record['id'] ?? null)) {
                        $records[(int) $record['id']] = $record;
                    }
                }
            }
        }

        // Every image must resolve the way the image pipeline resolves it.
        $elements = $this->attachmentElements($records);
        $unresolvedImages = [];

        foreach (\array_keys($collector->imageUrls()) as $url) {
            if (! XpathHelper::findAttachmentByUrl($elements, $url) instanceof SimpleXMLElement) {
                $unresolvedImages[] = $url;
            }
        }

        // Every permalink must match an item read, the attachments just read included.
        $known = $itemLinks;

        foreach ($records as $record) {
            if (\is_string($record['link'] ?? null)) {
                // Only the key is read, and array_diff_key() compares keys alone.
                // @pest-mutate-ignore: TrueToFalse
                $known[AttachmentReferenceCollector::normalizeLink($record['link'])] = true;
            }
        }

        $unresolvedLinks = [];

        foreach (\array_keys(\array_diff_key($collector->links(), $known)) as $link) {
            // The last segment names a slug; one an item read carries resolves to that item.
            // A normalized link always holds a slash, so the position is never false.
            // @pest-mutate-ignore: RemoveIntegerCast
            $segment = \strtolower(\rawurldecode(\substr($link, (int) \strrpos($link, '/') + 1)));

            if (! isset($itemSlugs[$segment])) {
                $unresolvedLinks[] = $link;
            }
        }

        if ($unresolvedImages !== [] || $unresolvedLinks !== []) {
            return $this->allAttachments(
                $client,
                $query,
                'unresolved_references',
                \count($ids),
                \count($paths),
                $unresolvedImages,
                $unresolvedLinks
            );
        }

        // A named reference the site does not serve is reported, never dropped in silence.
        $unserved = \array_diff_key($collector->namedIds(), $records);

        foreach ($unserved as $id => $reference) {
            ImportWarningManager::addWarning('migration_api', 'unserved_attachment_reference', [
                'attachment_id' => $id,
                'shape' => $reference['shape'],
                'item' => $reference['item'],
            ]);
        }

        $unservedIds = \array_keys($unserved);
        \sort($unservedIds);

        $this->attachmentResolution = [
            'resolution' => 'by_reference',
            'reason' => 'all_references_resolved',
            'ids_requested' => \count($ids),
            'paths_requested' => \count($paths),
            'records' => \count($records),
            'unresolved_image_count' => 0,
            'unresolved_images' => [],
            'unresolved_link_count' => 0,
            'unresolved_links' => [],
            'unserved_ids' => $unservedIds,
        ];

        // The caller only iterates the records, so their keys are never read.
        // @pest-mutate-ignore: UnwrapArrayValues
        return \array_values($records);
    }

    /**
     * Split reference values into requests bounded by count and by encoded size.
     *
     * The count bound is the API's `file[]` cap. The size bound exists because a path is
     * encoded into the query string at roughly three times its length, and a hundred long
     * paths measure over 8 KB — past Apache's default `LimitRequestLine` of 8190 and nginx's
     * default header buffer — which would answer `414` and abort the run.
     *
     * @template T of int|string
     *
     * @param  string  $parameter  The query parameter the values go under
     * @param  list<T>  $values  The values to send
     * @param  int<1, max>  $maxCount  The most values one request carries
     * @return list<non-empty-list<T>> The chunks, in order
     */
    private function referenceChunks(
        string $parameter,
        array $values,
        int $maxCount
    ): array {
        $chunks = [];
        $chunk = [];
        $bytes = 0;

        foreach ($values as $value) {
            // Encoded as the HTTP client encodes it: `name%5BN%5D=value&`.
            $size = \strlen(\rawurlencode(\sprintf('%s[%d]', $parameter, \count($chunk))))
                + \strlen(\rawurlencode((string) $value))
                + 2;

            if ($chunk !== [] && (\count($chunk) >= $maxCount || $bytes + $size > self::REFERENCE_QUERY_BYTES)) {
                $chunks[] = $chunk;
                $chunk = [];
                $bytes = 0;
            }

            $chunk[] = $value;
            $bytes += $size;
        }

        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * Read every attachment, recording why a scoped run widened to it.
     *
     * @param  Client  $client  A client addressed to the site's own REST root
     * @param  array<string, scalar>  $query  The query every post read carries
     * @param  string  $reason  `cheaper` or `unresolved_references`
     * @param  int  $idsRequested  The ids the references named
     * @param  int  $pathsRequested  The files the references named
     * @param  list<string>  $unresolvedImages  Image URLs no referenced record resolved
     * @param  list<string>  $unresolvedLinks  Permalinks no item read matched
     * @return list<mixed> Every attachment record
     */
    private function allAttachments(
        Client $client,
        array $query,
        string $reason,
        int $idsRequested,
        int $pathsRequested,
        array $unresolvedImages,
        array $unresolvedLinks
    ): array {
        // A paginated read already answers a list.
        // @pest-mutate-ignore: UnwrapArrayValues
        $records = \array_values($client->paginate('posts/attachment', null, $query));

        $this->attachmentResolution = [
            'resolution' => 'full',
            'reason' => $reason,
            'ids_requested' => $idsRequested,
            'paths_requested' => $pathsRequested,
            'records' => \count($records),
            'unresolved_image_count' => \count($unresolvedImages),
            'unresolved_images' => \array_slice($unresolvedImages, 0, self::REPORTED_EXAMPLES),
            'unresolved_link_count' => \count($unresolvedLinks),
            'unresolved_links' => \array_slice($unresolvedLinks, 0, self::REPORTED_EXAMPLES),
            'unserved_ids' => [],
        ];

        // Widening for cost is a choice with nothing to explain; widening because a reference
        // could not be shown resolved is worth a line, since it is what a narrowed read costs.
        if ($reason === 'unresolved_references') {
            ImportWarningManager::addWarning('migration_api', 'attachment_read_widened', [
                'unresolved_images' => \count($unresolvedImages),
                'unresolved_links' => \count($unresolvedLinks),
                'examples' => \array_slice(\array_merge($unresolvedImages, $unresolvedLinks), 0, 5),
            ]);
        }

        return $records;
    }

    /**
     * Attachment records as the XML elements `XpathHelper` matches against.
     *
     * Only the file URL is carried, which is all `findAttachmentByUrl()` reads.
     *
     * @param  array<int, array<array-key, mixed>>  $records  Attachment records, keyed by id
     * @return list<SimpleXMLElement> One element per record with a file URL
     */
    private function attachmentElements(
        array $records
    ): array {
        $xml = '<rss xmlns:wp="'.self::NAMESPACES['xmlns:wp'].'"><channel>';

        foreach ($records as $record) {
            $attachment = $record['attachment'] ?? null;
            $url = \is_array($attachment) ? ($attachment['url'] ?? null) : null;

            // An empty URL is written as an empty element, which no image URL matches
            if (! \is_string($url)) {
                continue;
            }

            // The URL is element text, where a quote needs no escaping, so the quote flag
            // cannot change what is written.
            // @pest-mutate-ignore: BitwiseOrToBitwiseAnd
            $xml .= '<item><wp:attachment_url>'
                .\htmlspecialchars($url, \ENT_XML1 | \ENT_QUOTES, 'UTF-8')
                .'</wp:attachment_url></item>';
        }

        $document = \simplexml_load_string($xml.'</channel></rss>');

        // Every value is an integer or escaped text, so the document always parses and
        // this only types what follows.
        // @pest-mutate-ignore: FalseToTrue, RemoveEarlyReturn
        if ($document === false) {
            return [];
        }

        $elements = [];

        foreach ($document->channel->item as $item) {
            $elements[] = $item;
        }

        return $elements;
    }
}
