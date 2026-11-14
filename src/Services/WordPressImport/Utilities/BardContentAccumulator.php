<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\TableProcessor;

/**
 * Gathers the nodes of content being built for Bard into the parts the content
 * is split into: text, and the images, lists and tables that each become a set
 * or a part of their own (#327, #394, #492).
 *
 * Split out of {@see TextProcessor} so each file's mutation run fits the CI limit
 * (#549). {@see TextProcessor::processForBard()} is the entry point.
 *
 * @internal
 */
final class BardContentAccumulator
{
    /**
     * The elements Bard reads through, so a table can be lifted out of one with
     * nothing lost but the wrapper, which Bard does not keep anyway (#327). Bard has
     * no disclosure node and reads a `<details>` and its summary as paragraphs, so a
     * `<details>` is one of them too (#394).
     *
     * @var list<string>
     */
    private const array TABLE_WRAPPER_TAGS = [
        'article',
        'aside',
        'center',
        'details',
        'div',
        'figure',
        'font',
        'main',
        'p',
        'section',
        'span',
    ];

    /**
     * The lists a table of data is lifted out of, ending the list ahead of the table
     * and resuming it after, since a set cannot sit inside a list item (#394).
     *
     * @var list<string>
     */
    private const array TABLE_LIST_TAGS = [
        'ol',
        'ul',
    ];

    /**
     * A table and its parts, which reach this list only when the table does not hold
     * data, so each is read through whether or not it holds another table (#327).
     *
     * @var list<string>
     */
    private const array TABLE_PART_TAGS = [
        'caption',
        'table',
        'tbody',
        'td',
        'tfoot',
        'th',
        'thead',
        'tr',
    ];

    /**
     * The elements an image in content is lifted out of, ending the element ahead of
     * the image and resuming it after, since a set is valid only at the top of the
     * document (#492). They are the table wrappers other than `<figure>`, which is
     * imported whole as an image; the page structure an export can carry, such as a
     * whole `<body>`; and the inline elements around text. An image in any other
     * element, such as a list or a heading, stays where it is and is reported.
     *
     * @var list<string>
     */
    private const array IMAGE_WRAPPER_TAGS = [
        'a',
        'abbr',
        'article',
        'aside',
        'b',
        'body',
        'center',
        'cite',
        'del',
        'details',
        'div',
        'em',
        'font',
        'footer',
        'header',
        'i',
        'ins',
        'label',
        'main',
        'mark',
        'nav',
        'p',
        's',
        'section',
        'small',
        'span',
        'strike',
        'strong',
        'sub',
        'sup',
        'u',
    ];

    /**
     * The HTML of the first part of each element with an `id` that the content being
     * built was split into around an image, for `BardTexstyleAttributeMapper` to
     * match the `id` against (#500). See {@see self::imageParts()}.
     */
    private static string $splitIdParts = '';

    /**
     * The split parts gathered so far for the content being built.
     */
    public static function splitIdParts(): string
    {
        return self::$splitIdParts;
    }

    /**
     * Replaces the split parts gathered so far, as a call that builds content
     * starts or ends.
     *
     * @param  string  $parts  The parts to hold from now on
     * @return string The parts held until now
     */
    public static function swapSplitIdParts(string $parts): string
    {
        $held = self::$splitIdParts;
        self::$splitIdParts = $parts;

        return $held;
    }

    /**
     * Returns a node's HTML, or an empty string when there is no node.
     *
     * @param  DOMDocument  $doc  The document the node belongs to
     * @param  ?DOMNode  $node  The node, or null for none
     * @return string The node's HTML, or '' for no node
     */
    public static function nodeHtml(
        DOMDocument $doc,
        ?DOMNode $node
    ): string {
        // ---
        // Read the node's HTML; there is none for no node. Every caller passes a node it
        // has checked exists (an `<img>` it counted, or the node the loop is on), so the
        // null and false results only satisfy the nullable `item()` and `saveHTML()`
        // types. saveHTML() throws for a node from another document rather than
        // returning false, and every caller passes $doc's own.
        // ---
        // @pest-mutate-ignore: InstanceOfToTrue, FalseToTrue
        $html = $node instanceof DOMNode ? $doc->saveHTML($node) : false;

        // Return the HTML, or '' for no node
        // @pest-mutate-ignore: FalseToTrue, EmptyStringToNotEmpty
        return $html === false ? '' : $html;
    }

    /**
     * Parses the content accumulated so far onto the Bard content and clears it.
     *
     * Content that is only whitespace is kept, so it joins whatever comes next.
     *
     * @param  string  &$currentContent  The accumulated HTML, cleared once parsed
     * @param  array<int|string, mixed>  &$bardContent  The Bard content to append to
     * @param  BardParser  $bardParser  The parser for the accumulated HTML
     */
    public static function flushContent(
        string &$currentContent,
        array &$bardContent,
        BardParser $bardParser
    ): void {
        // Keep whitespace-only content to join the next node
        if (\trim($currentContent) === '') {
            return;
        }

        // Append the parsed content and clear the buffer
        $bardContent = \array_merge(
            $bardContent,
            $bardParser->parse($currentContent)
        );
        $currentContent = '';
    }

    /**
     * Adds a node's content to the accumulated content, with its plain tables as sets.
     *
     * Bard has no table node, so a `<table>` left in the accumulated content loses its
     * rows and cells, and a set is valid only at the top of the document. So a table
     * is added as a `table` set, and a plain wrapper around one is read child by child
     * (#327). A table that does not hold data, such as the page layout of the exports'
     * email newsletters, is read the same way, and its links and lists stay in Bard
     * instead of in one cell's HTML. A list around a table of data is ended ahead of
     * the table and resumed after it (#394). A table in a quote stays as it was: its
     * wrapper is structure that a set could not sit inside. A table that holds only
     * photos and their captions is added as one image set per photo, since a set's
     * cell cannot render an image; a photo that does not import keeps its caption
     * as a paragraph (#489). An image in the rest of the content is imported as an
     * image set in its place, see {@see self::accumulateImages()} (#492).
     *
     * @param  DOMNode  $node  The node to add
     * @param  DOMDocument  $doc  The document the node belongs to
     * @param  ?DOMElement  $figure  The figure the node is a child of, when there is one
     * @param  string  $currentContent  The accumulated content
     * @param  array<int|string, mixed>  $bardContent  The Bard content so far
     * @param  BardParser  $bardParser  The parser for the accumulated content
     * @param  Closure(string): (array<int|string, mixed>|null)  $importImage  Imports an image or a figure as an image set
     */
    public static function accumulateContent(
        DOMNode $node,
        DOMDocument $doc,
        ?DOMElement $figure,
        string &$currentContent,
        array &$bardContent,
        BardParser $bardParser,
        Closure $importImage
    ): void {
        $tableProcessor = new TableProcessor;

        // A table of photos is added as an image set per photo, with its caption
        if ($node instanceof DOMElement && $node->tagName === 'table') {
            $photos = $tableProcessor->photoFigures($node);

            if ($photos !== null) {
                // Parse the content accumulated ahead of the table first
                self::flushContent(
                    $currentContent,
                    $bardContent,
                    $bardParser
                );

                foreach ($photos as $photo) {
                    $imageSet = $importImage($photo['figure']);

                    if ($imageSet !== null && $imageSet !== []) {
                        $bardContent[] = $imageSet;

                        continue;
                    }

                    // A photo that does not import leaves its caption as a paragraph
                    if ($photo['caption'] !== '') {
                        $bardContent = \array_merge(
                            $bardContent,
                            $bardParser->parse('<p>'.\htmlspecialchars($photo['caption'], ENT_QUOTES | ENT_HTML5).'</p>')
                        );
                    }
                }

                return;
            }
        }

        // A table of data is added as a set, unless it has no rows to import
        if (
            $node instanceof DOMElement
            && $node->tagName === 'table'
            && $tableProcessor->isDataTable($node)
        ) {
            $tableSet = $tableProcessor->renderSet($node, $figure);

            if ($tableSet !== null) {
                // Parse the content accumulated ahead of the table first
                self::flushContent(
                    $currentContent,
                    $bardContent,
                    $bardParser
                );

                $bardContent = \array_merge(
                    $bardContent,
                    $bardParser->parse($tableSet)
                );

                return;
            }
        }

        // A list around a table of data is split around the table
        if (
            $node instanceof DOMElement
            && \in_array($node->tagName, self::TABLE_LIST_TAGS, true)
            && self::containsDataTable($node)
        ) {
            self::accumulateList(
                $node,
                $doc,
                $currentContent,
                $bardContent,
                $bardParser,
                $importImage
            );

            return;
        }

        // Anything but a wrapper around a table, or part of a layout table, is ordinary content
        if (
            ! $node instanceof DOMElement
            || (
                ! \in_array($node->tagName, self::TABLE_PART_TAGS, true)
                && (
                    ! \in_array($node->tagName, self::TABLE_WRAPPER_TAGS, true)
                    || $node->getElementsByTagName('table')->length === 0
                )
            )
        ) {
            // An image in the content becomes an image set in its place (#492)
            if ($node instanceof DOMElement && self::holdsImage($node)) {
                self::accumulateImages(
                    $node,
                    $doc,
                    $currentContent,
                    $bardContent,
                    $bardParser,
                    $importImage
                );

                return;
            }

            $currentContent .= self::nodeHtml(
                $doc,
                $node
            );

            return;
        }

        // A figure around a table of data lends the set its caption and classes
        $tableFigure = $node->tagName === 'figure' && self::holdsDataTable($node)
            ? $node
            : null;

        // Read the wrapper child by child, leaving out the caption the set takes
        foreach ($node->childNodes as $child) {
            if ($tableFigure instanceof DOMElement && $child->nodeName === 'figcaption') {
                continue;
            }

            self::accumulateContent(
                $child,
                $doc,
                $tableFigure,
                $currentContent,
                $bardContent,
                $bardParser,
                $importImage
            );
        }

        // Parse what the wrapper left, so it does not join the content after it
        self::flushContent(
            $currentContent,
            $bardContent,
            $bardParser
        );
    }

    /**
     * Whether an element is an image or holds one at any depth.
     *
     * @param  DOMElement  $element  The element
     * @return bool True for an `<img>`, or an element with an `<img>` in it
     */
    private static function holdsImage(
        DOMElement $element
    ): bool {
        return $element->tagName === 'img'
            || $element->getElementsByTagName('img')->length > 0;
    }

    /**
     * Adds content that holds an image, with each image as an image set in its place.
     *
     * Bard has no image node, so an image left in the accumulated content is dropped
     * (#492). The content is split into parts around each image (see
     * {@see self::imageParts()}): each part is added as content, and each image is
     * imported as an image set after the content ahead of it.
     *
     * @param  DOMElement  $element  The image, or the element that holds one
     * @param  DOMDocument  $doc  The document the element belongs to
     * @param  string  $currentContent  The accumulated content
     * @param  array<int|string, mixed>  $bardContent  The Bard content so far
     * @param  BardParser  $bardParser  The parser for the accumulated content
     * @param  Closure(string): (array<int|string, mixed>|null)  $importImage  Imports an image or a figure as an image set
     */
    private static function accumulateImages(
        DOMElement $element,
        DOMDocument $doc,
        string &$currentContent,
        array &$bardContent,
        BardParser $bardParser,
        Closure $importImage
    ): void {
        foreach (self::imageParts($doc, $element) as [$isImage, $part]) {
            // A part around the images is ordinary content
            if (! $isImage) {
                $currentContent .= self::nodeHtml(
                    $doc,
                    $part
                );

                continue;
            }

            // An image or a figure is imported after the content ahead of it
            self::flushContent(
                $currentContent,
                $bardContent,
                $bardParser
            );

            $html = self::nodeHtml(
                $doc,
                $part
            );
            $imageSet = $importImage($html);

            if ($imageSet !== null && $imageSet !== []) {
                $bardContent[] = $imageSet;

                continue;
            }

            self::addFailedImage(
                $part,
                $html,
                $bardContent,
                $bardParser
            );
        }
    }

    /**
     * Splits an element around the images in it, in document order.
     *
     * An `<img>` or a `<figure>` is one image part, so a figure is imported whole, as
     * a figure at the top of the content is. An element in {@see self::IMAGE_WRAPPER_TAGS}
     * is ended ahead of each image in it, at any depth, and resumed after it: each
     * part is a copy of the element holding the content between two images, so text
     * keeps its order and stays in its paragraph, and an inline element around an
     * image is resumed inside the paragraph after it. A part that holds an element,
     * such as a shortcode's set or a rule, is kept; a part of only whitespace is
     * not. Any other element, such as a list or a heading, is one content part that
     * keeps its images, and each of them is reported, since a set cannot be lifted
     * out of it.
     *
     * The element's first content part keeps its `id`, and no later part, so an anchor
     * to the element lands at its start. `BardTexstyleAttributeMapper` finds an `id` by
     * its element's whole text, which no part holds, so the first part's HTML is kept
     * in {@see self::$splitIdParts} for it to match (#500). A link around
     * an image is not kept. A nested image is imported wherever it appears, without
     * the de-duplication `processForBard()` applies to images at the top of the
     * content.
     *
     * @param  DOMDocument  $doc  The document the element belongs to
     * @param  DOMElement  $element  The image, or the element that holds one
     * @return list<array{bool, DOMElement}> Each part, with true for an image
     */
    private static function imageParts(
        DOMDocument $doc,
        DOMElement $element
    ): array {
        // An image or a figure is imported whole
        if ($element->tagName === 'img' || $element->tagName === 'figure') {
            return [[true, $element]];
        }

        // An element a set could not be lifted out of keeps its images, reported
        if (! \in_array($element->tagName, self::IMAGE_WRAPPER_TAGS, true)) {
            foreach ($element->getElementsByTagName('img') as $image) {
                self::reportImage(
                    self::nodeHtml($doc, $image),
                    "An image in a <{$element->tagName}> was not imported."
                );
            }

            return [[false, $element]];
        }

        $parts = [];
        $part = null;

        foreach ($element->childNodes as $child) {
            // A child without an image joins the open part of this element
            if (! $child instanceof DOMElement || ! self::holdsImage($child)) {
                $part ??= self::emptyCopy($element);
                $part->appendChild($child->cloneNode(true));

                continue;
            }

            // A child's own content parts join this element's; its images end it
            foreach (self::imageParts($doc, $child) as [$isImage, $childPart]) {
                if (! $isImage) {
                    $part ??= self::emptyCopy($element);
                    $part->appendChild($childPart->cloneNode(true));

                    continue;
                }

                self::addImagePart(
                    $parts,
                    $part
                );
                $part = null;
                $parts[] = [true, $childPart];
            }
        }

        // Add the part of the element after its last image
        self::addImagePart(
            $parts,
            $part
        );

        // Keep the HTML of the element's first content part, so its `id` is matched to
        // that part's Bard node and to no later part's (#500)
        if ($element->hasAttribute('id')) {
            foreach ($parts as [$isImage, $contentPart]) {
                if (! $isImage) {
                    self::$splitIdParts .= self::nodeHtml($doc, $contentPart);

                    break;
                }
            }
        }

        return $parts;
    }

    /**
     * A copy of an element without its content, to hold one part of it.
     *
     * @param  DOMElement  $element  The element
     * @return DOMElement The copy
     */
    private static function emptyCopy(
        DOMElement $element
    ): DOMElement {
        $copy = $element->cloneNode(false);
        \assert($copy instanceof DOMElement);

        return $copy;
    }

    /**
     * Adds a content part of an element split around an image, when it holds an
     * element or any text that is not whitespace.
     *
     * @param  list<array{bool, DOMElement}>  $parts  The parts so far
     * @param  ?DOMElement  $part  The part, or null when none is open
     */
    private static function addImagePart(
        array &$parts,
        ?DOMElement $part
    ): void {
        // A part of only whitespace would add an empty element
        if (
            ! $part instanceof DOMElement
            || ($part->childElementCount === 0 && \trim($part->textContent) === '')
        ) {
            return;
        }

        $parts[] = [false, $part];
    }

    /**
     * Records an image or a figure that did not import, keeping a figure's caption
     * as a paragraph so no text is lost with the image.
     *
     * @param  DOMElement  $image  The `<img>` or the `<figure>`
     * @param  string  $html  Its HTML
     * @param  array<int|string, mixed>  $bardContent  The Bard content so far
     * @param  BardParser  $bardParser  The parser for the caption
     */
    public static function addFailedImage(
        DOMElement $image,
        string $html,
        array &$bardContent,
        BardParser $bardParser
    ): void {
        self::reportImage(
            $html,
            'An image in the content did not import.'
        );

        // A figure that does not import keeps its caption as a paragraph
        $caption = $image->getElementsByTagName('figcaption')->item(0);
        $captionText = \trim($caption->textContent ?? '');

        if ($captionText !== '') {
            $bardContent = \array_merge(
                $bardContent,
                $bardParser->parse('<p>'.\htmlspecialchars($captionText, ENT_QUOTES | ENT_HTML5).'</p>')
            );
        }
    }

    /**
     * Reports an image in the content that was not imported, naming the page.
     *
     * @param  string  $image  The image's HTML
     * @param  string  $reason  Why it was not imported
     */
    private static function reportImage(
        string $image,
        string $reason
    ): void {
        ImportWarningManager::addWarning(
            'content',
            'images_not_imported',
            [
                'page_link' => ProcessingContext::getWordPressPageLink() ?? '',
                'content_snippet' => \mb_substr($image, 0, 100)
                    .(\mb_strlen($image) > 100 ? '...' : ''),
                'reason' => $reason,
            ]
        );
    }

    /**
     * Adds a list that holds a table of data, ending the list ahead of each table and
     * resuming it after.
     *
     * The items before a table, and the content of its own item before it, stay in
     * the list; the table is read as its own content; the rest of its item and the
     * items after it start a list of the same kind. A resumed ordered list starts at
     * the number of its first item, so the numbering carries on across the table. A
     * list nested in the item that holds the table is read the same way, and comes
     * out after the content before it rather than nested in it (#394).
     *
     * @param  DOMElement  $list  The `<ol>` or `<ul>` that holds a table of data
     * @param  DOMDocument  $doc  The document the list belongs to
     * @param  string  $currentContent  The accumulated content
     * @param  array<int|string, mixed>  $bardContent  The Bard content so far
     * @param  BardParser  $bardParser  The parser for the accumulated content
     * @param  Closure(string): (array<int|string, mixed>|null)  $importImage  Imports an image or a figure as an image set
     */
    private static function accumulateList(
        DOMElement $list,
        DOMDocument $doc,
        string &$currentContent,
        array &$bardContent,
        BardParser $bardParser,
        Closure $importImage
    ): void {
        // The number of the item being read, and the part of the list not yet added
        $number = self::listStart($list);
        $part = null;

        foreach ($list->childNodes as $child) {
            // An item without a table, or anything else, joins the open part as it is
            if (
                ! $child instanceof DOMElement
                || $child->tagName !== 'li'
                || ! self::containsDataTable($child)
            ) {
                if ($child instanceof DOMElement || \trim($child->textContent) !== '') {
                    $part ??= self::listPart($list, $number);
                    $part->appendChild($child->cloneNode(true));
                }

                if ($child instanceof DOMElement && $child->tagName === 'li') {
                    $number++;
                }

                continue;
            }

            // An item with a table keeps its content around the table in the list
            $item = null;

            foreach ($child->childNodes as $grandchild) {
                if (
                    ! $grandchild instanceof DOMElement
                    || (! self::isDataTable($grandchild) && ! self::containsDataTable($grandchild))
                ) {
                    if ($grandchild instanceof DOMElement || \trim($grandchild->textContent) !== '') {
                        $part ??= self::listPart($list, $number);
                        $item ??= $part->appendChild($child->cloneNode(false));
                        $item->appendChild($grandchild->cloneNode(true));
                    }

                    continue;
                }

                // End the list ahead of the table, and read the table as its own content
                self::addListPart(
                    $part,
                    $doc,
                    $currentContent,
                    $bardContent,
                    $bardParser
                );
                $part = null;
                $item = null;

                self::accumulateContent(
                    $grandchild,
                    $doc,
                    null,
                    $currentContent,
                    $bardContent,
                    $bardParser,
                    $importImage
                );
                self::flushContent(
                    $currentContent,
                    $bardContent,
                    $bardParser
                );
            }

            $number++;
        }

        // Add the part of the list after the last table
        self::addListPart(
            $part,
            $doc,
            $currentContent,
            $bardContent,
            $bardParser
        );
    }

    /**
     * Parses a part of a split list onto the Bard content, when it has any.
     *
     * @param  ?DOMElement  $part  The part of the list, or null when none is open
     * @param  DOMDocument  $doc  The document the part belongs to
     * @param  string  $currentContent  The accumulated content
     * @param  array<int|string, mixed>  $bardContent  The Bard content so far
     * @param  BardParser  $bardParser  The parser for the accumulated content
     */
    private static function addListPart(
        ?DOMElement $part,
        DOMDocument $doc,
        string &$currentContent,
        array &$bardContent,
        BardParser $bardParser
    ): void {
        // A part is opened only to hold content, so there is nothing to add without one
        if (! $part instanceof DOMElement) {
            return;
        }

        $currentContent .= self::nodeHtml(
            $doc,
            $part
        );

        self::flushContent(
            $currentContent,
            $bardContent,
            $bardParser
        );
    }

    /**
     * Starts a part of a split list: an empty list of the same kind and attributes,
     * whose numbering, for an ordered list, starts at the given item.
     *
     * @param  DOMElement  $list  The list being split
     * @param  int  $number  The number of the part's first item
     * @return DOMElement The empty part
     */
    private static function listPart(
        DOMElement $list,
        int $number
    ): DOMElement {
        // Copy the list without its items
        $part = $list->cloneNode(false);
        \assert($part instanceof DOMElement);

        // Number an ordered list from its first item; the first part keeps the source's
        if ($list->tagName === 'ol' && $number !== self::listStart($list)) {
            $part->setAttribute('start', (string) $number);
        }

        return $part;
    }

    /**
     * The number of a list's first item: an ordered list's `start`, or 1.
     *
     * @param  DOMElement  $list  The list
     * @return int The number of its first item
     */
    private static function listStart(
        DOMElement $list
    ): int {
        // A missing or malformed start numbers from 1, as a browser does
        $start = \filter_var(
            $list->getAttribute('start'),
            FILTER_VALIDATE_INT
        );

        return \is_int($start) ? $start : 1;
    }

    /**
     * Whether an element is a table of data.
     *
     * @param  DOMElement  $element  The element
     * @return bool True for a `<table>` that holds data
     */
    private static function isDataTable(
        DOMElement $element
    ): bool {
        return $element->tagName === 'table'
            && (new TableProcessor)->isDataTable($element);
    }

    /**
     * Whether an element holds a table of data at any depth.
     *
     * @param  DOMElement  $element  The element
     * @return bool True when one of its descendants is a table of data
     */
    private static function containsDataTable(
        DOMElement $element
    ): bool {
        foreach ($element->getElementsByTagName('table') as $table) {
            if (self::isDataTable($table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a figure holds a table of data directly.
     *
     * A table of photos is not one: its photos become image sets rather than a
     * `table` set, so the figure has no set to lend its caption to, and the caption
     * is read as content after the photos (#489).
     *
     * @param  DOMElement  $figure  The figure element
     * @return bool True when one of the figure's children is a table of data
     */
    private static function holdsDataTable(
        DOMElement $figure
    ): bool {
        $tableProcessor = new TableProcessor;

        foreach ($figure->childNodes as $child) {
            if (
                $child instanceof DOMElement
                && $child->tagName === 'table'
                && $tableProcessor->isDataTable($child)
                && $tableProcessor->photoFigures($child) === null
            ) {
                return true;
            }
        }

        return false;
    }
}
