<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Console\Commands\Concerns;

use App\Services\BardContentSanitizer;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Statamic\Facades\Collection;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;

/**
 * The content type, taxonomy and handle mapping tables of the WordPress import command.
 *
 * Part of the `statamic:import:wordpress` command, split out of
 * {@see ImportWordPress} so that each file's
 * mutation run fits the CI limit (#546). The methods run on the command and use its
 * properties and output.
 */
trait ImportWordPressMappings
{
    /**
     * Get the default content type to destination mappings.
     *
     * @return array<string, array{type: string, handle: string, name: string}>
     */
    public static function getDefaultMappings(): array
    {
        return [
            'page' => [
                'type' => 'collection',
                'handle' => 'pages',
                'name' => 'Pages',
            ],
            'post' => [
                'type' => 'collection',
                'handle' => 'posts',
                'name' => 'Posts',
            ],
            'uamspeople' => [
                'type' => 'collection',
                'handle' => 'people',
                'name' => 'People',
            ],
            'tshowcase' => [
                'type' => 'collection',
                'handle' => 'people',
                'name' => 'People',
            ],
            'redirect_rule' => [
                'type' => 'global',
                'handle' => 'redirects',
                'name' => 'Redirects',
            ],
            'tablepress_table' => [
                'type' => 'collection',
                'handle' => 'tables',
                'name' => 'Tables',
            ],
            'kb' => [
                'type' => 'collection',
                'handle' => 'knowledge_bases',
                'name' => 'Knowledge Base Articles',
            ],
            'logo-list-item' => [
                'type' => 'collection',
                'handle' => 'logo_list_items',
                'name' => 'Logo List Items',
            ],
            // WP Adverts listings, into the board built by UAMS-Web/uams-statamic-archive#2096
            'advert' => [
                'type' => 'collection',
                'handle' => 'classifieds',
                'name' => 'Classifieds',
            ],
            'wp_block' => [
                'type' => 'collection',
                'handle' => 'reusable_content',
                'name' => 'Reusable Blocks',
            ],
            'nav_menu_item' => [
                'type' => 'navigation',
                'handle' => 'primary_navigation',
                'name' => 'Navigation Menu Items',
            ],
        ];
    }

    /**
     * Get WordPress taxonomy to Statamic taxonomy mappings.
     *
     * @return array<string, array{type: string, handle: string, name: string}>
     */
    public static function getTaxonomyMappings(): array
    {
        return [
            'category' => [
                'type' => 'taxonomy',
                'handle' => 'post_categories',
                'name' => 'Post Categories',
            ],
            'post_tag' => [
                'type' => 'taxonomy',
                'handle' => 'post_tags',
                'name' => 'Post Tags',
            ],
            'logo-list-category' => [
                'type' => 'taxonomy',
                'handle' => 'logos_item_categories',
                'name' => 'Logos, Item Categories',
            ],
            'logo-list-label' => [
                'type' => 'taxonomy',
                'handle' => 'logos_item_labels',
                'name' => 'Logos, Item Labels',
            ],
            'uamspeople-categories' => [
                'type' => 'taxonomy',
                'handle' => 'people_groups',
                'name' => 'People, Groups',
            ],
            'tshowcase-categories' => [
                'type' => 'taxonomy',
                'handle' => 'people_groups',
                'name' => 'People, Groups',
            ],
            'kbtag' => [
                'type' => 'taxonomy',
                'handle' => 'knowledge_base_tags',
                'name' => 'Knowledge Base, Tags',
            ],
            'mkb_faq_category' => [
                'type' => 'taxonomy',
                'handle' => 'faq_categories',
                'name' => 'FAQ, Categories',
            ],
            'advert_category' => [
                'type' => 'taxonomy',
                'handle' => 'classified_categories',
                'name' => 'Classifieds, Categories',
            ],
        ];
    }

    /**
     * Maps WordPress handles to Statamic handles based on type.
     *
     * @param  string  $wordpressHandle  The WordPress handle (taxonomy or post type)
     * @param  string  $type  The type of mapping ('taxonomy' or 'collection')
     * @return ?string The corresponding Statamic handle, or null if not found
     *
     * @throws InvalidArgumentException If the type is not supported
     */
    public static function mapWordPressToStatamicHandle(
        string $wordpressHandle,
        string $type
    ): ?string {
        // Get the appropriate mappings based on type
        $mappings = match ($type) {
            // Handle taxonomy mappings
            'taxonomy' => self::getTaxonomyMappings(),

            // Handle collection mappings
            'collection' => self::getDefaultMappings(),

            // Throw exception for unsupported mapping types
            default => throw new InvalidArgumentException(
                "Unsupported mapping type '{$type}'. Supported types are: taxonomy, collection"
            ),
        };

        // Look up the specific mapping
        $mapping = $mappings[$wordpressHandle] ?? null;

        // Return handle if this is the correct type mapping
        return ($mapping !== null && $mapping['type'] === $type)
            ? $mapping['handle']
            : null;
    }

    /**
     * Supported content types for direct import.
     *
     * The 'wp_navigation' post type will not be supported. Added in WordPress 5.9,
     * this was the post type used to save the Navigation block's "items" (i.e., data).
     *
     * @var list<string>
     */
    protected array $supportedContentTypes = [
        'page',
        'post',
        'uamspeople',
        'tshowcase',
        'redirect_rule',
        'kb',
        'logo-list-item',
        'advert',
        'nav_menu_item',
    ];

    /**
     * The Bard content sanitizer service instance.
     */
    protected BardContentSanitizer $bardContentSanitizer;

    /**
     * Initial filesize of the app directory before import.
     *
     * @var int
     */
    protected $initialFilesize;
}
