<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Replicates WordPress's wpautop() function to format text with HTML paragraph
 * and line break tags.
 *
 * Formats raw text or HTML by converting double line breaks to <p> tags and
 * single line breaks to <br /> tags (when enabled), mimicking WordPress's WYSIWYG
 * field behavior for ACF fields. Protects specific tags (e.g., <pre>, <script>,
 * <style>, <svg>, <math>) from formatting changes to preserve their content. Used
 * in WordPress import parsers to preprocess content before Tiptap parsing.
 *
 * ## Why preserving <script>/<style>/<svg>/<math> here is safe
 *
 * Read in isolation, carrying those tags through untouched looks like an XSS
 * hole. It is not, and the reason is entirely downstream: this class only ever
 * *formats* — it is a preprocessing step, never a sanitizer, and its output is
 * handed straight to {@see BardParser::parse()}. That parser's Tiptap schema is
 * an allowlist, so a node or mark the schema does not declare is discarded at
 * parse time. `<script>`, `<iframe>`, event-handler attributes and
 * `javascript:`/`data:` hrefs never reach a Statamic entry.
 *
 * Preserving them here is deliberate rather than incidental: mangling a
 * `<script>` mid-format could split it into fragments the parser no longer
 * recognizes as one node, which is how a sanitizer-by-accident starts leaking.
 * Keeping each tag intact means the allowlist sees it whole and drops it whole.
 *
 * Do not add stripping here — it would duplicate the real control and mask a
 * regression in it. The invariant is asserted host-side in `uams-statamic`
 * (BardParserScriptStrippingTest, WordPressImportScriptStrippingTest), which
 * pins the tiptap-php build that actually parses production content.
 *
 * @see  https://github.com/UAMS-Web/uams-statamic-archive/issues/1742
 */
class WpAutoP
{
    /**
     * Block-level element names, as WordPress 7.0.7's `wpautop()` lists them.
     */
    private const string ALLBLOCKS = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary)';

    /**
     * Elements whose newlines the line-break pass leaves alone.
     */
    private const array PRESERVED_ELEMENTS = ['script', 'style', 'svg', 'math'];

    /**
     * Formats text with paragraph and line break tags, as WordPress's `wpautop()`.
     *
     * Follows `wpautop()` from WordPress 7.0.7's `wp-includes/formatting.php` step for
     * step, so the same input gives the same HTML. Two things differ, and only where
     * a regular expression in WordPress's version fails: the newlines inside
     * `<script>`, `<style>`, `<svg>` and `<math>` are found by a string scan rather
     * than a regular expression, so a large element cannot exhaust PCRE's backtrack
     * limit and empty the text as it does in WordPress; and a step whose regular
     * expression fails anyway keeps its input and is reported through
     * {@see ImportWarningManager}, rather than passing on null (#146).
     *
     * Content that holds blocks is WordPress's to leave alone: `do_blocks()` unhooks
     * `wpautop()` for it, so callers skip this for such content, as
     * {@see TextProcessor::processForBard()} does.
     *
     * @param  string  $text  The input text or HTML to format (e.g., WYSIWYG field content)
     * @param  bool  $br  Whether to convert single line breaks to <br /> tags (default: true)
     * @return string The formatted HTML with <p> and optional <br /> tags
     */
    public function process(
        string $text,
        bool $br = true
    ): string {
        if (\trim($text) === '') {
            return '';
        }

        $preTags = [];
        $allblocks = self::ALLBLOCKS;

        // Pad the end for processing
        $text .= "\n";

        // Set <pre> elements aside behind placeholders, to restore them untouched
        if (\str_contains($text, '<pre')) {
            $textParts = \explode('</pre>', $text);
            $lastPart = \array_pop($textParts);
            $text = '';
            $i = 0;

            foreach ($textParts as $textPart) {
                $start = \strpos($textPart, '<pre');

                // A closing </pre> with no opener is left as it is
                if ($start === false) {
                    $text .= $textPart;

                    continue;
                }

                $name = "<pre wp-pre-tag-{$i}></pre>";
                $preTags[$name] = \substr($textPart, $start).'</pre>';
                $text .= \substr($textPart, 0, $start).$name;
                $i++;
            }

            $text .= $lastPart;
        }

        // Turn two <br> tags in a row into a paragraph break
        $text = self::pregReplace('|<br\s*/?>\s*<br\s*/?>|', "\n\n", $text);

        // Add a paragraph break above block-level opening tags
        $text = self::pregReplace("!(<{$allblocks}[\s/>])!", "\n\n$1", $text);

        // Add a paragraph break below block-level closing tags
        $text = self::pregReplace("!(</{$allblocks}>)!", "$1\n\n", $text);

        // Add a paragraph break after <hr>, which has no closing tag
        $text = self::pregReplace('!(<hr\s*?/?>)!', "$1\n\n", $text);

        // Standardize newlines
        $text = \str_replace(["\r\n", "\r"], "\n", $text);

        // Mark the newlines inside tags, so they are not read as line breaks
        $text = self::replaceInHtmlTags($text, "\n", ' <!-- wpnl --> ');

        // Keep <option> elements out of paragraphs
        if (\str_contains($text, '<option')) {
            $text = self::pregReplace('|\s*<option|', '<option', $text);
            $text = self::pregReplace('|</option>\s*|', '</option>', $text);
        }

        // Keep the inside of an <object>, and its <param> and <embed>, together
        if (\str_contains($text, '</object>')) {
            $text = self::pregReplace('|(<object[^>]*>)\s*|', '$1', $text);
            $text = self::pregReplace('|\s*</object>|', '</object>', $text);
            $text = self::pregReplace('%\s*(</?(?:param|embed)[^>]*>)\s*%', '$1', $text);
        }

        // Keep <audio> and <video>, and their <source> and <track>, together
        if (\str_contains($text, '<source') || \str_contains($text, '<track')) {
            $text = self::pregReplace('%([<\[](?:audio|video)[^>\]]*[>\]])\s*%', '$1', $text);
            $text = self::pregReplace('%\s*([<\[]/(?:audio|video)[>\]])%', '$1', $text);
            $text = self::pregReplace('%\s*(<(?:source|track)[^>]*>)\s*%', '$1', $text);
        }

        // Keep a <figcaption> against what it captions
        if (\str_contains($text, '<figcaption')) {
            $text = self::pregReplace('|\s*(<figcaption[^>]*>)|', '$1', $text);
            $text = self::pregReplace('|</figcaption>\s*|', '</figcaption>', $text);
        }

        // Collapse runs of blank lines
        $text = self::pregReplace("/\n\n+/", "\n\n", $text);

        // Split into paragraphs. A limit of 0 means no limit, as -1 does.
        // @pest-mutate-ignore: IncrementInteger
        $paragraphs = \preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // A fixed pattern on a string cannot fail to split, so no test reaches this
        // @pest-mutate-ignore: FalseToTrue
        if ($paragraphs === false) {
            self::reportFailure('split into paragraphs', $text);

            // @pest-mutate-ignore: RemoveEarlyReturn
            return $text;
        }

        // Wrap each paragraph in <p>
        $text = '';
        foreach ($paragraphs as $paragraph) {
            $text .= '<p>'.\trim($paragraph, "\n")."</p>\n";
        }

        // Remove paragraphs of nothing but whitespace
        $text = self::pregReplace('|<p>\s*</p>|', '', $text);

        // Close a paragraph left open inside a <div>, <address> or <form>
        $text = self::pregReplace('!<p>([^<]+)</(div|address|form)>!', '<p>$1</p></$2>', $text);

        // Unwrap a block tag that sits alone in a paragraph
        $text = self::pregReplace("!<p>\s*(</?{$allblocks}[^>]*>)\s*</p>!", '$1', $text);

        // Unwrap list items from paragraphs
        $text = self::pregReplace('|<p>(<li.+?)</p>|', '$1', $text);

        // Move a paragraph around a <blockquote> inside it
        $text = self::pregReplace('!<p><blockquote((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>!i', '<blockquote$1><p>', $text);
        $text = \str_replace('</blockquote></p>', '</p></blockquote>', $text);

        // Drop a paragraph opener before a block tag, and a closer after one
        $text = self::pregReplace("!<p>\s*(</?{$allblocks}[^>]*>)!", '$1', $text);
        $text = self::pregReplace("!(</?{$allblocks}[^>]*>)\s*</p>!", '$1', $text);

        // Turn the remaining single newlines into <br />
        if ($br) {
            // Set aside the newlines inside script, style, SVG and MathML elements
            $text = self::preserveElementNewlines($text);

            // Normalize <br> spellings
            $text = \str_replace(['<br>', '<br/>'], '<br />', $text);

            // Add <br /> before each newline that has none
            $text = self::pregReplace('|(?<!<br />)\s*\n|', "<br />\n", $text);

            // Restore the newlines set aside
            $text = \str_replace('<WPPreserveNewline />', "\n", $text);
        }

        // Drop a <br /> after a block tag
        $text = self::pregReplace("!(</?{$allblocks}[^>]*>)\s*<br />!", '$1', $text);

        // Drop a <br /> before some block tags
        $text = self::pregReplace('!<br />(\s*</?(?:p|li|div|dl|dd|dt|th|pre|td|ul|ol)[^>]*>)!', '$1', $text);

        // Drop the newline before the last </p>
        $text = self::pregReplace("|\n</p>$|", '</p>', $text);

        // Restore <pre> elements. str_replace() reads an array's values in order
        // whatever their keys, so array_values() only documents the intent.
        if ($preTags !== []) {
            // @pest-mutate-ignore: UnwrapArrayValues
            $text = \str_replace(\array_keys($preTags), \array_values($preTags), $text);
        }

        // Restore the newlines marked inside tags
        if (\str_contains($text, '<!-- wpnl -->')) {
            return \str_replace([' <!-- wpnl --> ', '<!-- wpnl -->'], "\n", $text);
        }

        return $text;
    }

    /**
     * Replace a string inside HTML tags and comments only, as WordPress's
     * `wp_replace_in_html_tags()` does with one replacement pair.
     *
     * @param  string  $haystack  The HTML to search
     * @param  string  $needle  The string to find inside tags
     * @param  string  $replace  Its replacement
     * @return string The HTML with the needle replaced inside tags
     */
    private static function replaceInHtmlTags(
        string $haystack,
        string $needle,
        string $replace
    ): string {
        // Split into text and tags; the tags land at the odd indexes
        $parts = \preg_split(self::htmlSplitRegex(), $haystack, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            self::reportFailure('find newlines inside tags', $haystack);

            return $haystack;
        }

        // Replace inside each tag
        $changed = false;
        $count = \count($parts);
        for ($i = 1; $i < $count; $i += 2) {
            if (\str_contains($parts[$i], $needle)) {
                $parts[$i] = \str_replace($needle, $replace, $parts[$i]);
                $changed = true;
            }
        }

        return $changed ? \implode('', $parts) : $haystack;
    }

    /**
     * The pattern that splits HTML into text and tags, as WordPress's
     * `get_html_split_regex()` builds it, comments and CDATA included.
     *
     * @return string The PCRE pattern
     */
    private static function htmlSplitRegex(): string
    {
        // A comment, after the `<`, up to `-->` or the end of the input
        $comments = '!(?:-(?!->)[^\-]*+)*+(?:-->)?';

        // A CDATA section, after the `<`, up to `]]>` or the end of the input
        $cdata = '!\[CDATA\[[^\]]*+(?:](?!]>)[^\]]*+)*+(?:]]>)?';

        // An escaped element is a comment or CDATA; anything else ends at `>`
        $escaped = '(?=!--|!\[CDATA\[)(?(?=!-)'.$comments.'|'.$cdata.')';

        return '/(<(?'.$escaped.'|[^>]*>?))/';
    }

    /**
     * Swap each newline inside a `<script>`, `<style>`, `<svg>` or `<math>` element for
     * a placeholder, as `wpautop()`'s `/<(script|style|svg|math).*?<\/\1>/s` pass does.
     *
     * A scan for the opener and its closing tag finds the same spans as that pattern,
     * including its case-sensitive names and an opener whose name only begins with one
     * of the four, without a backtrack limit to exhaust on a large element.
     *
     * @param  string  $text  The HTML to scan
     * @return string The HTML with those newlines replaced by `<WPPreserveNewline />`
     */
    private static function preserveElementNewlines(string $text): string
    {
        $result = '';
        $offset = 0;
        $length = \strlen($text);

        while ($offset < $length) {
            // Find the earliest opener of any of the four elements
            $start = null;
            $name = '';
            foreach (self::PRESERVED_ELEMENTS as $element) {
                $position = \strpos($text, '<'.$element, $offset);
                if ($position !== false && ($start === null || $position < $start)) {
                    $start = $position;
                    $name = $element;
                }
            }

            if ($start === null) {
                break;
            }

            // An opener with no closing tag after it starts no span; look past it
            $close = \strpos($text, '</'.$name.'>', $start + 1 + \strlen($name));
            if ($close === false) {
                $result .= \substr($text, $offset, $start + 1 - $offset);
                $offset = $start + 1;

                continue;
            }

            // Copy the text before the span, then the span with its newlines set aside
            $end = $close + \strlen($name) + 3;
            $result .= \substr($text, $offset, $start - $offset)
                .\str_replace("\n", '<WPPreserveNewline />', \substr($text, $start, $end - $start));
            $offset = $end;
        }

        return $result.\substr($text, $offset);
    }

    /**
     * Run preg_replace, reporting a failure and keeping the subject when it fails.
     *
     * @param  string  $pattern  PCRE pattern
     * @param  string  $replacement  Replacement string
     * @param  string  $subject  Input subject
     * @return string Updated text, or the subject unchanged on failure
     */
    private static function pregReplace(
        string $pattern,
        string $replacement,
        string $subject
    ): string {
        $result = \preg_replace($pattern, $replacement, $subject);

        if (! \is_string($result)) {
            self::reportFailure($pattern, $subject);

            return $subject;
        }

        return $result;
    }

    /**
     * Report a step that could not run, so the summary names the content it skipped.
     *
     * @param  string  $step  The pattern or step that failed
     * @param  string  $subject  The text it was run on
     */
    private static function reportFailure(
        string $step,
        string $subject
    ): void {
        ImportWarningManager::addWarning(
            'content',
            'wpautop_step_failed',
            [
                'step' => $step,
                'error' => \preg_last_error_msg(),
                'length' => \strlen($subject),
                'excerpt' => \mb_substr($subject, 0, 120),
            ]
        );
    }
}
