<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\Concerns;

use SimpleXMLElement;
use Statamic\Entries\Entry;
use Statamic\Fieldtypes\RowId;
use Statamic\Sites\Site;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\KbEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\FileProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Reads a knowledge base article's attachments, local and external.
 *
 * Part of {@see KbEntryCreator},
 * split out so that each file's mutation run fits the CI limit (#553). The methods run
 * on the creator and use its properties.
 */
trait ReadsKbAttachments
{
    /**
     * Get article attachments from _mkb_article_attachments postmeta.
     *
     * This method extracts attachment data from the article's postmeta and processes
     * both asset attachments (by ID) and external files (with URL and label).
     * It builds the replicator structure for the attachment field.
     *
     * @param  SimpleXMLElement  $item  The wp:post element
     * @param  string  $site  Selected site handle
     * @param  AssetImporter  $assetImporter  Service for importing assets
     * @param  OutputInterface  $output  Console output interface
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @return list<array<int|string, mixed>> Array of processed attachments for the replicator field
     */
    protected function getArticleAttachments(
        SimpleXMLElement $item,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        XmlParser $xmlParser,
        ?string $pageUrl = null
    ): array {
        $processedAttachments = []; // Initialize an array to store processed attachment data for the article

        // Extract all postmeta elements from the article XML
        $postmeta = XpathHelper::xpathElements($item, 'wp:postmeta');

        // Iterate through each postmeta element to find _mkb_article_attachments
        foreach ($postmeta as $meta) {
            // Get the meta_key value
            $metaKey = XpathHelper::getStringValue(
                $meta,
                'wp:meta_key'
            );

            // ---
            // Check if the current meta key is '_mkb_article_attachments', which indicates
            // that this meta element contains attachment data (e.g., files) associated with
            // the article
            // ---
            if ($metaKey === '_mkb_article_attachments') {
                // Get the meta_value, which should be a serialized array
                $metaValue = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_value'
                );

                // Unserialize the value to get the attachments array
                $attachmentData = @\unserialize($metaValue, ['allowed_classes' => false]);

                // Ensure that the decoded attachment data is an array before processing
                if (\is_array($attachmentData)) {
                    // Get XML attachments for asset import
                    $xmlAttachments = $xmlParser->getAttachments();

                    // Process each attachment in the array
                    foreach ($attachmentData as $attachmentItem) {
                        // Check if this is an asset attachment (numeric string)
                        if (\is_numeric($attachmentItem)) {
                            // This is an asset attachment ID, find the attachment URL and import as file
                            $attachmentUrl = $this->findAttachmentUrl(
                                (string) $attachmentItem,
                                $xmlAttachments
                            );

                            // ---
                            // Import only an attachment the export carries; the file import
                            // helper imports nothing for a blank URL
                            // ---
                            if ($attachmentUrl !== null) {
                                // Use the file import helper which handles disabled uploads gracefully
                                $assetPath = FileProcessor::importFileWithDisabledUploadHandling(
                                    $attachmentUrl,
                                    $site,
                                    $assetImporter,
                                    $output,
                                    'files',
                                    'attachment',
                                    'KbEntryCreator::createEntries',
                                    $pageUrl
                                );

                                // Check if the asset path was successfully determined (e.g., the file
                                // was imported or located). Only proceed if a valid asset path exists.
                                if ($assetPath !== null && $assetPath !== '') {
                                    // Add asset set to replicator
                                    $processedAttachments[] = [
                                        'id' => (new RowId)->generate(),
                                        'type' => 'asset',
                                        'enabled' => true,
                                        'asset' => $assetPath,
                                    ];
                                }
                            }
                        } elseif (
                            \is_string($attachmentItem)
                            && str_starts_with($attachmentItem, 'EXTERNAL')
                        ) {
                            // ---
                            // Check if the attachment item is a string that starts with 'EXTERNAL',
                            // indicating it represents an external file reference rather than a standard
                            // attachment. If so, parse the EXTERNAL string to extract label and URL.
                            // ---

                            // This is an external file, parse the EXTERNAL string
                            $externalData = $this->parseExternalAttachment($attachmentItem);

                            // Check if the external data was successfully parsed from the EXTERNAL string.
                            if ($externalData !== null && $externalData !== []) {
                                // Add external_file set to replicator
                                $attachmentSet = [
                                    'id' => (new RowId)->generate(),
                                    'type' => 'external_file',
                                    'enabled' => true,
                                    'label' => $externalData['label'],
                                    'url' => $externalData['url'],
                                ];

                                // ---
                                // Note: Size is parsed from EXTERNAL string but not stored
                                // in the replicator item
                                // ---

                                // Add the attachment set to the processed attachments array
                                $processedAttachments[] = $attachmentSet;
                            }
                        }
                    }
                }

                // Stop searching after finding the relevant meta_key
                break;
            }
        }

        // ---
        // Return the array of processed attachments, which may include imported assets and
        // external file references, to be used in the entry's replicator field or
        // elsewhere.
        // ---
        return $processedAttachments;
    }

    /**
     * Find the attachment URL for a given attachment ID.
     *
     * This method searches through the XML attachments to find the URL
     * for a specific attachment ID.
     *
     * @param  string  $attachmentId  The attachment ID to search for
     * @param  array<int|string, mixed>  $xmlAttachments  Array of XML attachment elements
     * @return ?string The attachment URL or null if not found
     */
    protected function findAttachmentUrl(
        string $attachmentId,
        array $xmlAttachments
    ): ?string {
        // ---
        // Iterate through all provided XML attachment elements to find the one that
        // matches the requested attachment ID.
        // ---
        foreach ($xmlAttachments as $attachment) {
            if (! $attachment instanceof SimpleXMLElement) {
                continue;
            }

            // Extract the post ID from the current attachment XML element
            $postId = XpathHelper::getStringValue(
                $attachment,
                'wp:post_id'
            );

            // Check if the extracted post ID matches the requested attachment ID
            if ($postId === $attachmentId) {
                // If a match is found, return the attachment URL from the XML element
                return XpathHelper::getStringValue(
                    $attachment,
                    'wp:attachment_url'
                );
            }
        }

        // If no matching attachment is found after iterating all elements, return null
        return null;
    }

    /**
     * Parse external attachment string to extract URL, label, and size.
     *
     * Parses strings in the format: EXTERNAL{{url}}LABEL{{label}}SIZE{{size}}
     * where LABEL and SIZE are optional. The keys are directly concatenated
     * to the values without any separators.
     *
     * @param  string  $externalString  The external attachment string
     * @return array<int|string, mixed>|null Array with 'url', 'label', and optionally 'size' keys, or null if parsing fails
     */
    protected function parseExternalAttachment(
        string $externalString
    ): ?array {
        // Check if the string starts with EXTERNAL{{
        if (
            ! str_starts_with($externalString, 'EXTERNAL{{')
        ) {
            return null;
        }

        // Remove the EXTERNAL{{ prefix
        $remaining = \substr(
            $externalString,
            10
        );

        // Find the position of LABEL{{ if it exists
        $labelPos = \strpos(
            $remaining,
            'LABEL{{'
        );

        // Find the position of SIZE{{ if it exists
        $sizePos = \strpos(
            $remaining,
            'SIZE{{'
        );

        // Extract the URL (everything before LABEL{{ or SIZE{{ or the end)
        $urlEndPos = \strlen($remaining);

        // ---
        // If LABEL{{ is found in the string, set the end position of the URL to the
        // position where LABEL{{ starts. This means the URL ends right before the label
        // section.
        // ---
        if ($labelPos !== false) {
            $urlEndPos = $labelPos;
        } elseif ($sizePos !== false) {
            // ---
            // If LABEL{{ is not found but SIZE{{ is found, set the end position of the URL to
            // the position where SIZE{{ starts. This means the URL ends right before the size
            // section.
            // ---

            $urlEndPos = $sizePos;
        }

        // Extract the URL substring from the start of the string up to
        // $urlEndPos, and trim any leading or trailing whitespace to ensure a clean URL
        // value.
        $url = \trim(
            \substr(
                $remaining,
                0,
                $urlEndPos
            )
        );

        // Remove all trailing closing braces from the URL if present.
        $url = $this->removeTrailingClosingBraces($url);

        // Extract the label if LABEL{{ exists
        // Default to URL as label
        $label = $url;

        // If LABEL{{ is found, extract the label value from the string
        if ($labelPos !== false) {
            // Calculate the start position of the label content (after "LABEL{{")
            $labelStart = $labelPos + 7;

            // Initialize label end position to the end of the string
            $labelEndPos = \strlen($remaining);

            // If SIZE{{ comes after LABEL{{, set label end to start of SIZE{{; a SIZE{{
            // that is absent (false) never does
            if ($sizePos > $labelPos) {
                $labelEndPos = $sizePos;
            }

            // Extract the label content substring between label start and label end
            $labelContent = \substr(
                $remaining,
                $labelStart,
                $labelEndPos - $labelStart
            );

            // Remove all trailing closing braces if present at the end of the label content.
            $labelContent = $this->removeTrailingClosingBraces($labelContent);

            // Trim whitespace from the extracted label content for a clean label value
            $label = \trim($labelContent);
        }

        // Extract the size if SIZE{{ exists
        $size = null;

        // If SIZE{{ is found, extract the size value from the string
        if ($sizePos !== false) {
            // Calculate the start position of the size content (after "SIZE{{")
            $sizeStart = $sizePos + 6;

            // Extract the size content substring from size start to the end of the string
            $sizeContent = \substr(
                $remaining,
                $sizeStart
            );

            // Remove all trailing closing braces if present at the end of the size content.
            $sizeContent = $this->removeTrailingClosingBraces($sizeContent);

            // Trim whitespace from the extracted size content for a clean size value
            $size = \trim($sizeContent);
        }

        // Return the parsed URL, label, and size as an associative array
        $result = [
            'url' => $url,
            'label' => $label,
        ];

        // Check if the size was extracted from the EXTERNAL string
        if ($size !== null) {
            // Add size to the result array
            $result['size'] = $size;
        }

        // Return the result array
        return $result;
    }
}
