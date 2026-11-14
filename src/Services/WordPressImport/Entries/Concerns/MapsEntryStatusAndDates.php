<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Concerns;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SlugGenerator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Maps a WordPress item's status, visibility, WP-Members gate and dates onto an entry.
 *
 * Split out of {@see BaseEntryCreator} so that each file's mutation run fits
 * the CI limit (#550). The methods run on the creator and use its properties
 * and static caches, which stay declared on that class.
 */
trait MapsEntryStatusAndDates
{
    /**
     * Read an item's WP-Members `_wpmem_block` postmeta, or '' when it has none (wordpress-importer-archive#1240).
     *
     * @param  SimpleXMLElement  $item  The WordPress `<item>` element
     * @return string The raw meta value, trimmed
     */
    protected function readWpMembersBlock(
        SimpleXMLElement $item
    ): string {
        return \trim(XpathHelper::getStringValue(
            $item,
            "wp:postmeta[wp:meta_key='_wpmem_block']/wp:meta_value"
        ));
    }

    /**
     * Whether a `_wpmem_block` value gates the item: `1` blocks it for logged-out
     * visitors and `2` hides it (wordpress-importer-archive#1240). `0`, '' and anything else leave it open.
     *
     * @param  string  $wpmemBlock  The `_wpmem_block` postmeta value
     * @return bool True when WP-Members blocks or hides the item
     */
    protected static function isWpMembersGated(
        string $wpmemBlock
    ): bool {
        // WP-Members writes only '1' and '2', so a loose match differs only on numeric
        // variants it never produces.
        // @pest-mutate-ignore: TrueToFalse
        return \in_array(\trim($wpmemBlock), ['1', '2'], true);
    }

    /**
     * Decide whether an item whose blueprint has no `status_and_visibility` imports published (wordpress-importer-archive#1241).
     *
     * Knowledge-base articles, adverts and reusable blocks set the published flag
     * straight from `wp:status`, and none of their blueprints carries the `private`
     * state `mapStatusAndVisibility()` uses for a gated item. So a published item that
     * WP-Members blocks (`_wpmem_block` `1`) or hides (`2`) imports unpublished instead.
     * That keeps it out of public view until its owner decides how it migrates, which
     * is the same fail-closed rule, and the import names it the same way.
     *
     * @param  bool  $published  Whether the item would import published from its status alone
     * @param  string  $wpmemBlock  The item's `_wpmem_block` postmeta value, or '' when absent
     * @param  string  $caller  The creator method deciding, used in the log message
     * @param  OutputInterface  $output  Console output interface
     * @return bool False for a published item WP-Members gates, otherwise $published unchanged
     */
    protected function publishUnlessWpMembersGated(
        bool $published,
        string $wpmemBlock,
        string $caller,
        OutputInterface $output
    ): bool {
        // Leave an unpublished item, or one WP-Members leaves open, as it is
        if (! $published || ! self::isWpMembersGated($wpmemBlock)) {
            return $published;
        }

        // Name the item, so its owner can decide how it migrates
        $this->reportWpMembersGate($caller, $wpmemBlock, 'unpublished', $output);

        return false;
    }

    /**
     * Report a published item that WP-Members gates, by URL, in the log and the console.
     *
     * @param  string  $caller  The method deciding, used as the log message prefix
     * @param  string  $wpmemBlock  The `_wpmem_block` postmeta value
     * @param  string  $importedAs  What the item imports as instead: `private` or `unpublished`
     * @param  OutputInterface  $output  Console output interface
     */
    private function reportWpMembersGate(
        string $caller,
        string $wpmemBlock,
        string $importedAs,
        OutputInterface $output
    ): void {
        // Enhance log context with entry information
        $logData = LogContextHelper::enhanceWithEntryContext(
            ['wpmem_block' => $wpmemBlock]
        );

        Log::warning(
            LogContextHelper::formatMessageWithEntry(
                "{$caller}: WP-Members gates this item (_wpmem_block='{$wpmemBlock}'), importing it as {$importedAs} rather than published",
                $logData['page_url']
            ),
            $logData['context']
        );

        $pageUrl = $logData['page_url'];

        $output->writeln(\sprintf(
            '<comment>Imported as %s: WP-Members gates %s (_wpmem_block=%s).</comment>',
            $importedAs,
            $pageUrl !== null && $pageUrl !== '' ? $pageUrl : 'this item',
            $wpmemBlock
        ));
    }

    /**
     * Map WordPress status and visibility fields to Statamic fields.
     *
     * Maps wp:status, wp:post_password, and wp:post_date to Statamic's
     * status_and_visibility, password_protected, visibility_password, and date
     * fields based on the blueprint. Hashes the post password for secure storage.
     * Sets status_and_visibility to reflect the WordPress status without explicitly
     * setting a published flag. Sets password_protected to true when a password is
     * present, or false when no password is provided, ensuring consistent output
     * across all content types.
     *
     * A `future` post is held back in one of two ways, chosen by the blueprint. A
     * blueprint with a `publish_at` field schedules through that field, as the host
     * app's undated `people` collection does (wordpress-importer-archive#1233): the entry imports as `draft`
     * with `publish_at` set, and the host's scheduled job publishes it. Any other
     * blueprint gets `scheduled` and a dated entry, as before.
     *
     * A published item that WP-Members holds behind login (`_wpmem_block` `1`) or hides
     * (`2`) imports as `private` instead, so the import does not make public what
     * WordPress gated before its owner has decided how it migrates (wordpress-importer-archive#1240). Other
     * statuses keep their mapping.
     *
     * @param  string  $status  The wp:status value (e.g., 'draft', 'pending', 'private', 'future', 'publish')
     * @param  string  $postPassword  The wp:post_password value
     * @param  string  $postDate  The wp:post_date value
     * @param  array<int|string, mixed>  &$data  The entry data array to populate
     * @param  string  $collectionHandle  The collection handle
     * @param  OutputInterface  $output  Console output interface
     * @param  string  $postDateGmt  The wp:post_date_gmt value, used for `publish_at`
     * @param  string  $wpmemBlock  The item's `_wpmem_block` postmeta value, or '' when absent
     * @return bool True if date should be used as filename prefix (for wp:status=future)
     */
    protected function mapStatusAndVisibility(
        string $status,
        string $postPassword,
        string $postDate,
        array &$data,
        string $collectionHandle,
        OutputInterface $output,
        string $postDateGmt = '',
        // A missing block value gates nothing, and neither does any other a mutant gives
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        string $wpmemBlock = ''
    ): bool {
        $collection = CollectionFacade::findByHandle($collectionHandle);
        $blueprint = $collection?->entryBlueprint();
        $isScheduledPost = false;

        if (\is_object($blueprint) && \method_exists($blueprint, 'hasField')) {
            // Map wp:status to status_and_visibility
            if ($blueprint->hasField('status_and_visibility') === true) {
                switch ($status) {
                    case 'draft':
                        $data['status_and_visibility'] = 'draft';
                        break;
                    case 'pending':
                        $data['status_and_visibility'] = 'pending';
                        break;
                    case 'private':
                        $data['status_and_visibility'] = 'private';
                        break;
                    case 'future':
                        // Schedule through publish_at where the blueprint has it, since no status holds such an entry back
                        if ($blueprint->hasField('publish_at') === true) {
                            $data['status_and_visibility'] = 'draft';

                            // Leave publish_at unset when no date can be read, so the entry stays a draft
                            $publishAt = $this->resolveScheduledPublishAt($postDateGmt, $postDate);
                            if ($publishAt !== null) {
                                $data['publish_at'] = $publishAt;
                            }

                            break;
                        }

                        $data['status_and_visibility'] = 'scheduled';
                        $isScheduledPost = true;
                        break;
                    case 'publish':
                        $data['status_and_visibility'] = 'published';
                        break;
                    default:
                        // Enhance log context with entry information
                        $logData = LogContextHelper::enhanceWithEntryContext(
                            ['status' => $status]
                        );

                        // Log warning for unsupported status
                        Log::warning(
                            LogContextHelper::formatMessageWithEntry(
                                "BaseEntryCreator::mapStatusAndVisibility: Unsupported wp:status='$status', defaulting to draft",
                                $logData['page_url']
                            ),
                            $logData['context']
                        );
                        $data['status_and_visibility'] = 'draft';
                }

                // Fail closed on a published item WP-Members gates, rather than make it public
                if ($data['status_and_visibility'] === 'published' && self::isWpMembersGated($wpmemBlock)) {
                    $data['status_and_visibility'] = 'private';

                    // Name the item, so its owner can decide how it migrates
                    $this->reportWpMembersGate(
                        'BaseEntryCreator::mapStatusAndVisibility',
                        $wpmemBlock,
                        'private',
                        $output
                    );
                }
            }

            // Map wp:post_password to password_protected and visibility_password
            if ($blueprint->hasField('password_protected') === true) {
                if ($postPassword !== '') {
                    $data['password_protected'] = true;
                    if ($blueprint->hasField('visibility_password') === true) {
                        $hashedPassword = Hash::make($postPassword);
                        $data['visibility_password'] = $hashedPassword;
                        $data['is_password_hashed'] = true;
                    }
                } else {
                    $data['password_protected'] = false;
                }
            }

            // Map wp:post_date to date for a post scheduled by status rather than by publish_at
            if ($status === 'future' && $blueprint->hasField('publish_at') !== true && $blueprint->hasField('date') === true && $postDate !== '') {
                $data['date'] = $postDate;
            }
        } else {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                ['collection_handle' => $collectionHandle]
            );

            // Log warning for missing blueprint
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "BaseEntryCreator::mapStatusAndVisibility: No blueprint found for collection '$collectionHandle'",
                    $logData['page_url']
                ),
                $logData['context']
            );
        }

        return $isScheduledPost;
    }

    /**
     * Resolve the `publish_at` value for a `future` post.
     *
     * Returns the time in UTC, in the Date fieldtype's `Y-m-d H:i` save format. The
     * host app runs with `app.timezone` UTC, and the fieldtype reads a saved string
     * in that zone, so `wp:post_date_gmt` is the right source. WordPress writes an
     * all-zero GMT date for a post it never scheduled, and the export records no
     * site timezone, so the fallback reads `wp:post_date` as UTC and logs that the
     * time may be off by the site's offset. A date before year 1000, which is what
     * WordPress's all-zero placeholder parses to, counts as unreadable, so it can
     * never produce a publish time that is already due.
     *
     * @param  string  $postDateGmt  The wp:post_date_gmt value
     * @param  string  $postDate  The wp:post_date value
     * @return ?string The publish time, or null when neither date can be read
     */
    protected function resolveScheduledPublishAt(
        string $postDateGmt,
        string $postDate
    ): ?string {
        $utc = new DateTimeZone('UTC');

        // Prefer the GMT date
        $publishAt = $this->formatPublishAt($postDateGmt, $utc);
        if ($publishAt !== null) {
            return $publishAt;
        }

        // Fall back to the local date, read as UTC
        $publishAt = $this->formatPublishAt($postDate, $utc);

        // Enhance log context with entry information
        $logData = LogContextHelper::enhanceWithEntryContext(
            [
                'post_date_gmt' => $postDateGmt,
                'post_date' => $postDate,
                'publish_at' => $publishAt,
            ]
        );

        // Log that the scheduled time is approximate, or missing
        Log::warning(
            LogContextHelper::formatMessageWithEntry(
                $publishAt !== null
                    ? 'BaseEntryCreator::resolveScheduledPublishAt: No usable wp:post_date_gmt, so publish_at reads wp:post_date as UTC and may be off by the site offset'
                    : 'BaseEntryCreator::resolveScheduledPublishAt: No readable post date, so publish_at is unset and the entry stays a draft',
                $logData['page_url']
            ),
            $logData['context']
        );

        return $publishAt;
    }

    /**
     * Format a WordPress date string as a UTC `publish_at` value.
     *
     * @param  string  $date  The date string
     * @param  DateTimeZone  $utc  The UTC zone
     * @return ?string The `Y-m-d H:i` value, or null when the string is empty, unparsable or before year 1000
     */
    private function formatPublishAt(string $date, DateTimeZone $utc): ?string
    {
        // An empty string would parse as now, and so would one of NUL bytes, which trim() strips
        if (\trim($date) === '') {
            return null;
        }

        try {
            $time = new DateTimeImmutable($date, $utc)->setTimezone($utc);
        } catch (Exception) {
            return null;
        }

        // ---
        // WordPress's all-zero placeholder parses to a year before 1. A year string
        // compares as a number either way.
        // ---
        // @pest-mutate-ignore: RemoveIntegerCast
        if ((int) $time->format('Y') < 1000) {
            return null;
        }

        return $time->format('Y-m-d H:i');
    }

    /**
     * Extract the post's GMT date string from WordPress XML.
     *
     * @param  SimpleXMLElement  $xmlElement  The WordPress XML element (post, page, etc.)
     * @return string The wp:post_date_gmt value, or an empty string if not found
     */
    protected function extractPostDateGmtString(
        SimpleXMLElement $xmlElement
    ): string {
        // Extract wp:post_date_gmt using XpathHelper for consistency
        return XpathHelper::getStringValue(
            $xmlElement,
            'wp:post_date_gmt'
        );
    }

    /**
     * Generate a slug and ID for entries.
     *
     * @param  string  $baseSlug  The base slug from WordPress
     * @param  string  $postId  The WordPress post ID
     * @param  ?string  $title  The post title to use for slug generation when baseSlug is empty
     * @return list{string, string} Entry slug and unique entry ID
     */
    protected function generateSlugAndId(
        string $baseSlug,
        string $postId,
        ?string $title = null
    ): array {
        return SlugGenerator::generateSlugAndId(
            $baseSlug,
            $postId,
            $title
        );
    }

    /**
     * Extract post date string from WordPress XML with fallback to pubDate.
     *
     * Extracts the wp:post_date value from the XML element and falls back to
     * the pubDate element if post_date is empty. This ensures consistent date
     * handling across all entry creators.
     *
     * @param  SimpleXMLElement  $xmlElement  The WordPress XML element (post, page, etc.)
     * @return string The post date string or empty string if not found
     */
    protected function extractPostDateString(
        SimpleXMLElement $xmlElement
    ): string {
        // Extract wp:post_date using XpathHelper for consistency
        $postDate = XpathHelper::getStringValue(
            $xmlElement,
            'wp:post_date'
        );

        // Check if post_date is empty
        if ($postDate === '') {
            // Fall back to pubDate
            return (string) ($xmlElement->pubDate ?? '');
        }

        // Return the post date string
        return $postDate;
    }

    /**
     * Extract post modified date string from WordPress XML.
     *
     * Extracts the wp:post_modified value from the XML element. This ensures
     * consistent date handling across all entry creators, matching the logic
     * used for wp:post_date.
     *
     * @param  SimpleXMLElement  $xmlElement  The WordPress XML element (post, page, etc.)
     * @return string The post modified date string or empty string if not found
     */
    protected function extractPostModifiedDateString(
        SimpleXMLElement $xmlElement
    ): string {
        // Extract wp:post_modified using XpathHelper for consistency
        $postModified = XpathHelper::getStringValue(
            $xmlElement,
            'wp:post_modified'
        );

        // Return the post modified date string
        return $postModified;
    }

    /**
     * Add updated_at field to entry data from WordPress XML element.
     *
     * Extracts the wp:post_modified value from the WordPress XML element,
     * converts it to a UNIX timestamp, and adds it to the entry data array as
     * the updated_at field. Handles date extraction and parsing errors with
     * appropriate logging.
     *
     * @param  array<int|string, mixed>  &$data  The entry data array (passed by reference)
     * @param  SimpleXMLElement  $xmlElement  The WordPress XML element (post, page, etc.)
     * @param  string  $methodName  The name of the calling method for logging
     *                              (e.g., 'PostEntryCreator::createEntries')
     */
    protected function addUpdatedAtField(
        array &$data,
        SimpleXMLElement $xmlElement,
        string $methodName
    ): void {
        // Extract post modified date string from WordPress XML
        $postModifiedDate = $this->extractPostModifiedDateString($xmlElement);

        // Check if post modified date is available
        if ($postModifiedDate === '') {
            // Return early if no modified date available
            return;
        }

        try {
            // Convert modified date string to UNIX timestamp
            $modifiedDateTime = new DateTime($postModifiedDate);
            $data['updated_at'] = $modifiedDateTime->getTimestamp();
        } catch (Exception $exception) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'post_modified_date' => $postModifiedDate,
                    'error' => $exception->getMessage(),
                ]
            );

            // Log warning for failed date parsing
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "{$methodName}: Failed to parse post modified date string for updated_at field",
                    $logData['page_url']
                ),
                $logData['context']
            );
        }
    }

    /**
     * Create a DateTime object for scheduled posts.
     *
     * @param  string  $dateStr  The date string from WordPress
     * @param  bool  $isScheduledPost  Whether to create a date object (e.g., for wp:status=future)
     * @return ?DateTime The DateTime object or null if not needed
     */
    protected function createEntryDate(
        string $dateStr,
        bool $isScheduledPost
    ): ?DateTime {
        if ($isScheduledPost && $dateStr !== '') {
            return new DateTime($dateStr);
        }

        return null;
    }
}
