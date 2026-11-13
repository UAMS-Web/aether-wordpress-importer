<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\Traits;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\MigrationApiException;
use XMLWriter;

/**
 * Writes WXR part files for `WxrMaterializer`.
 *
 * Opens and closes each part, and writes its channel, authors, taxonomies and items
 * with the text and CDATA escaping XML 1.0 requires.
 */
trait WxrMaterializerXmlWriterTrait
{
    /**
     * Open a part and write everything that precedes its items.
     *
     * @param  string  $path  The part path to write
     * @param  array<array-key, mixed>  $channel  The site's discovery payload
     * @param  array<int, mixed>  $authors  The site's authors
     * @param  array<string, list<array<array-key, mixed>>>  $terms  Terms keyed by taxonomy
     * @return XMLWriter The opened writer
     *
     * @throws MigrationApiException When the part cannot be opened for writing
     */
    private function openPart(
        string $path,
        array $channel,
        array $authors,
        array $terms
    ): XMLWriter {
        $writer = new XMLWriter;

        // Stream to the file rather than building the document in memory — this package
        // processes multi-thousand-item exports in one process.
        if ($writer->openUri($path) === false) {
            throw new MigrationApiException(\sprintf(
                'Could not open "%s" to write a materialized WXR part.',
                $path
            ));
        }

        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('rss');
        $writer->writeAttribute('version', '2.0');

        foreach (self::NAMESPACES as $attribute => $uri) {
            $writer->writeAttribute($attribute, $uri);
        }

        $writer->startElement('channel');

        $this->writeChannel($writer, $channel);
        $this->writeAuthors($writer, $authors);
        $this->writeTaxonomies($writer, $terms);

        return $writer;
    }

    /**
     * Close a part, ending every element the open left on the stack.
     *
     * @param  XMLWriter  $writer  The writer to close
     */
    private function closePart(
        XMLWriter $writer
    ): void {
        // Ending the document closes </channel> and </rss>, and writes the part out
        $writer->endDocument();

        // endDocument() has already written everything to the file, so the flush only
        // makes that explicit.
        // @pest-mutate-ignore: RemoveMethodCall
        $writer->flush();
    }

    /**
     * Write the channel-level metadata every part repeats.
     *
     * @param  XMLWriter  $writer  The open writer
     * @param  array<array-key, mixed>  $channel  The site's discovery payload
     */
    private function writeChannel(
        XMLWriter $writer,
        array $channel
    ): void {
        $this->writeText($writer, 'title', $channel['title'] ?? null);
        $this->writeText($writer, 'link', $channel['link'] ?? null);
        $this->writeText($writer, 'description', $channel['description'] ?? null);
        $this->writeText($writer, 'language', $channel['language'] ?? null);

        $this->writeText($writer, 'wp:wxr_version', $channel['wxr_version'] ?? '1.2');
        $this->writeText($writer, 'wp:base_site_url', $channel['base_site_url'] ?? null);

        // `getBaseBlogUrl()` reads this, and consumers resolve every relative link against
        // it — an absent value silently turns site-relative URLs into broken ones.
        $this->writeText($writer, 'wp:base_blog_url', $channel['base_blog_url'] ?? null);
    }

    /**
     * Write the `wp:author` nodes.
     *
     * @param  XMLWriter  $writer  The open writer
     * @param  array<int, mixed>  $authors  The site's authors
     */
    private function writeAuthors(
        XMLWriter $writer,
        array $authors
    ): void {
        foreach ($authors as $author) {
            if (! \is_array($author)) {
                continue;
            }

            $writer->startElement('wp:author');
            $this->writeText($writer, 'wp:author_id', $author['id'] ?? null);
            $this->writeCdata($writer, 'wp:author_login', $author['login'] ?? null);
            $this->writeCdata($writer, 'wp:author_email', $author['email'] ?? null);
            $this->writeCdata($writer, 'wp:author_display_name', $author['display_name'] ?? null);
            $this->writeCdata($writer, 'wp:author_first_name', $author['first_name'] ?? null);
            $this->writeCdata($writer, 'wp:author_last_name', $author['last_name'] ?? null);
            $writer->endElement();
        }
    }

    /**
     * Write the channel-level taxonomy nodes.
     *
     * WXR carries three distinct legacy forms and consumers read all three: `wp:category`,
     * `wp:tag`, and the generic `wp:term`. `category` and `post_tag` get their legacy node
     * **and** a generic one, exactly as a real export does.
     *
     * @param  XMLWriter  $writer  The open writer
     * @param  array<string, list<array<array-key, mixed>>>  $terms  Terms keyed by taxonomy
     */
    private function writeTaxonomies(
        XMLWriter $writer,
        array $terms
    ): void {
        foreach ($terms as $taxonomy => $rows) {
            foreach ($rows as $term) {
                if ($taxonomy === 'category') {
                    $writer->startElement('wp:category');
                    $this->writeText($writer, 'wp:term_id', $term['id'] ?? null);
                    $this->writeText($writer, 'wp:category_nicename', $term['slug'] ?? null);
                    $this->writeText($writer, 'wp:category_parent', $term['parent_slug'] ?? null);
                    $this->writeCdata($writer, 'wp:cat_name', $term['name'] ?? null);
                    $this->writeCdata($writer, 'wp:category_description', $term['description'] ?? null);
                    $writer->endElement();
                }

                if ($taxonomy === 'post_tag') {
                    $writer->startElement('wp:tag');
                    $this->writeText($writer, 'wp:term_id', $term['id'] ?? null);
                    $this->writeText($writer, 'wp:tag_slug', $term['slug'] ?? null);
                    $this->writeCdata($writer, 'wp:tag_name', $term['name'] ?? null);
                    $this->writeCdata($writer, 'wp:tag_description', $term['description'] ?? null);
                    $writer->endElement();
                }

                $writer->startElement('wp:term');
                $this->writeText($writer, 'wp:term_id', $term['id'] ?? null);
                $this->writeText($writer, 'wp:term_taxonomy', $taxonomy);
                $this->writeText($writer, 'wp:term_slug', $term['slug'] ?? null);
                $this->writeText($writer, 'wp:term_parent', $term['parent_slug'] ?? null);
                $this->writeCdata($writer, 'wp:term_name', $term['name'] ?? null);
                $this->writeCdata($writer, 'wp:term_description', $term['description'] ?? null);
                $this->writeMeta($writer, 'wp:termmeta', $term['meta'] ?? null);
                $writer->endElement();
            }
        }
    }

    /**
     * Write one `<item>`.
     *
     * @param  XMLWriter  $writer  The open writer
     * @param  array<array-key, mixed>  $post  A post payload
     */
    private function writeItem(
        XMLWriter $writer,
        array $post
    ): void {
        $writer->startElement('item');

        $this->writeCdata($writer, 'title', $post['title'] ?? null);
        $this->writeText($writer, 'link', $post['link'] ?? null);
        $this->writeText($writer, 'pubDate', $post['date_created_gmt'] ?? null);

        // The author element is `dc:creator` and carries the login, not the display name —
        // author resolution matches on the login.
        $author = $post['author'] ?? null;
        $this->writeCdata(
            $writer,
            'dc:creator',
            \is_array($author) ? ($author['login'] ?? null) : null
        );

        $this->writeText($writer, 'guid', $post['guid'] ?? null);
        $this->writeCdata($writer, 'content:encoded', $post['content'] ?? null);
        $this->writeCdata($writer, 'excerpt:encoded', $post['excerpt'] ?? null);

        $this->writeText($writer, 'wp:post_id', $post['id'] ?? null);
        $this->writeText($writer, 'wp:post_date', $post['date_created'] ?? null);
        $this->writeText($writer, 'wp:post_date_gmt', $post['date_created_gmt'] ?? null);
        $this->writeText($writer, 'wp:post_modified', $post['date_modified'] ?? null);
        $this->writeText($writer, 'wp:post_modified_gmt', $post['date_modified_gmt'] ?? null);
        $this->writeText($writer, 'wp:comment_status', $post['comment_status'] ?? null);
        $this->writeText($writer, 'wp:ping_status', $post['ping_status'] ?? null);
        $this->writeCdata($writer, 'wp:post_name', $post['slug'] ?? null);
        $this->writeText($writer, 'wp:status', $post['status'] ?? null);
        $this->writeText($writer, 'wp:post_parent', $post['parent'] ?? null);
        $this->writeText($writer, 'wp:menu_order', $post['menu_order'] ?? null);
        $this->writeText($writer, 'wp:post_type', $post['type'] ?? null);
        $this->writeCdata($writer, 'wp:post_password', $post['post_password'] ?? null);
        $this->writeText($writer, 'wp:is_sticky', ($post['is_sticky'] ?? false) === true ? '1' : '0');

        // An attachment's URL lives in its own element, and the asset pipeline reads it
        // from there rather than from the guid.
        $attachment = $post['attachment'] ?? null;

        if (\is_array($attachment) && \is_string($attachment['url'] ?? null)) {
            $this->writeText($writer, 'wp:attachment_url', $attachment['url']);
        }

        $this->writeItemTerms($writer, $post['terms'] ?? null);
        $this->writeMeta($writer, 'wp:postmeta', $post['meta'] ?? null);

        $writer->endElement();
    }

    /**
     * Write an item's `<category>` nodes.
     *
     * The payload groups these by taxonomy; WXR flattens them into `<category>` elements
     * whose `domain` attribute carries the taxonomy and whose `nicename` carries the slug.
     *
     * @param  XMLWriter  $writer  The open writer
     * @param  mixed  $terms  The post's grouped terms
     */
    private function writeItemTerms(
        XMLWriter $writer,
        mixed $terms
    ): void {
        if (! \is_array($terms)) {
            return;
        }

        foreach ($terms as $taxonomy => $rows) {
            if (! \is_string($taxonomy) || ! \is_array($rows)) {
                continue;
            }

            foreach ($rows as $term) {
                if (! \is_array($term)) {
                    continue;
                }

                $writer->startElement('category');
                $writer->writeAttribute('domain', $taxonomy);
                $writer->writeAttribute('nicename', $this->scalarToString($term['slug'] ?? null));
                $writer->writeCdata($this->cdataSafe($this->scalarToString($term['name'] ?? null)));
                $writer->endElement();
            }
        }
    }

    /**
     * Write meta rows.
     *
     * Each key maps to a **list** of values, because `get_post_meta()` without a key returns
     * every row — so one key can produce several meta elements, exactly as WXR allows.
     *
     * @param  XMLWriter  $writer  The open writer
     * @param  string  $element  The wrapping element (`wp:postmeta` or `wp:termmeta`)
     * @param  mixed  $meta  The meta payload
     */
    private function writeMeta(
        XMLWriter $writer,
        string $element,
        mixed $meta
    ): void {
        if (! \is_array($meta)) {
            return;
        }

        foreach ($meta as $key => $values) {
            if (! \is_string($key)) {
                continue;
            }

            // A scalar value is still one row; normalizing here keeps the loop below honest
            // whether the API sends a list or a bare value.
            $rows = \is_array($values) ? $values : [$values];

            foreach ($rows as $value) {
                $writer->startElement($element);
                $this->writeCdata($writer, 'wp:meta_key', $key);
                $this->writeCdata($writer, 'wp:meta_value', $value);
                $writer->endElement();
            }
        }
    }

    /**
     * Write a plain-text element, skipping it entirely when there is no value.
     *
     * @param  XMLWriter  $writer  The open writer
     * @param  string  $element  The element name
     * @param  mixed  $value  The value to write
     */
    private function writeText(
        XMLWriter $writer,
        string $element,
        mixed $value
    ): void {
        if ($value === null || $value === '') {
            return;
        }

        $writer->writeElement($element, $this->sanitize($this->scalarToString($value)));
    }

    /**
     * Write a CDATA element, skipping it entirely when there is no value.
     *
     * @param  XMLWriter  $writer  The open writer
     * @param  string  $element  The element name
     * @param  mixed  $value  The value to write
     */
    private function writeCdata(
        XMLWriter $writer,
        string $element,
        mixed $value
    ): void {
        if ($value === null || $value === '') {
            return;
        }

        $writer->startElement($element);
        $writer->writeCdata($this->cdataSafe($this->scalarToString($value)));
        $writer->endElement();
    }

    /**
     * Render a value as a string, serializing anything that is not scalar.
     *
     * @param  mixed  $value  The value to render
     * @return string The rendered string
     */
    private function scalarToString(
        mixed $value
    ): string {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return '';
        }

        // A number is written as JSON writes it, which is how it arrived. A structured meta
        // value (WordPress serializes arrays into the meta table) round-trips as JSON rather
        // than being silently dropped. Encoding cannot fail on a value that was itself
        // decoded from JSON, so the guard satisfies the return type; the key is still
        // written either way, which keeps any future loss visible.
        $encoded = \json_encode($value);

        // @pest-mutate-ignore: FalseToTrue, EmptyStringToNotEmpty
        return $encoded === false ? '' : $encoded;
    }

    /**
     * Strip the characters XML 1.0 cannot represent.
     *
     * @param  string  $value  The value to filter
     * @return string The filtered value
     */
    private function sanitize(
        string $value
    ): string {
        $filtered = \preg_replace(self::ILLEGAL_CHARACTERS, '', $value);

        // `preg_replace` returns null only on a malformed UTF-8 subject, which cannot reach
        // here: `Client` decodes JSON, and `json_decode` rejects invalid UTF-8 outright, so
        // every string arriving from it is already valid. The coalesce satisfies the return
        // type rather than handling a state this path can produce — hence returning the
        // original value, never an empty string.
        return $filtered ?? $value;
    }

    /**
     * Make a value safe to place inside a CDATA section.
     *
     * @param  string  $value  The value to escape
     * @return string The escaped value
     */
    private function cdataSafe(
        string $value
    ): string {
        // A `]]>` inside the content would close the section early and truncate everything
        // after it — the split re-opens a new section so the bytes survive verbatim.
        return \str_replace(
            ']]>',
            ']]]]><![CDATA[>',
            $this->sanitize($value)
        );
    }
}
