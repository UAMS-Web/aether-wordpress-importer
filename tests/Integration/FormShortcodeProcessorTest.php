<?php

declare(strict_types=1);

/**
 * Pin Gravity Forms and Formidable shortcode output through their public APIs.
 *
 * The shared shortcode trait normalizes IDs, tracks references, and builds Bard
 * sets or page-builder blocks. Each plugin must use its own cached handles even
 * when another plugin has created a form with the same WordPress ID.
 *
 * @command  php vendor/bin/pest tests/Integration/FormShortcodeProcessorTest.php
 */

use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\FormidableFormsShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\GravityFormsShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

dataset('form shortcode plugins', [
    'Gravity Forms' => [GravityFormsShortcodeProcessor::class, 'Gravity Forms', 'uamsweb_block_gravityform', 'createdGravityFormsHandles'],
    'Formidable Forms' => [FormidableFormsShortcodeProcessor::class, 'Formidable Forms', 'uamsweb_block_formidable', 'createdFormidableFormsHandles'],
]);

/**
 * Build the concrete processor selected by the dataset.
 *
 * @param  string  $class  The processor class name
 * @return GravityFormsShortcodeProcessor|FormidableFormsShortcodeProcessor The processor
 */
function makeFormShortcodeProcessor(string $class): GravityFormsShortcodeProcessor|FormidableFormsShortcodeProcessor
{
    return match ($class) {
        GravityFormsShortcodeProcessor::class => new GravityFormsShortcodeProcessor,
        FormidableFormsShortcodeProcessor::class => new FormidableFormsShortcodeProcessor,
        default => throw new InvalidArgumentException('Unknown form shortcode processor fixture.'),
    };
}

/**
 * Read an attribute from a Bard set without assuming its nested shape.
 *
 * @param  array<int|string, mixed>  $set  The parsed set
 * @param  string  $key  The attribute name
 * @return mixed The attribute value, or null when absent
 */
function formShortcodeSetAttribute(array $set, string $key): mixed
{
    $attributes = $set['attrs'] ?? null;

    return \is_array($attributes) ? ($attributes[$key] ?? null) : null;
}

/**
 * Clear shortcode reference and handle caches between cases.
 */
function resetFormShortcodeProcessorState(): void
{
    // Reset both plugins because a worker can run either plugin's cases next.
    foreach ([GravityFormsShortcodeProcessor::class, FormidableFormsShortcodeProcessor::class] as $class) {
        $class::clearFormIds();

        foreach (['createdGravityFormsHandles', 'createdFormidableFormsHandles', 'createdNinjaFormsHandles'] as $property) {
            (new ReflectionProperty($class, $property))->setValue(null, []);
        }
    }
}

beforeEach(fn () => resetFormShortcodeProcessorState());
afterEach(fn () => resetFormShortcodeProcessorState());

it('normalizes shortcode IDs without losing valid zero or string references', function (
    string $class,
    string $plugin,
    string $blockType,
    string $cache,
    array $attributes,
    string $note,
    array $tracked
): void {
    // Parse through the concrete plugin rather than the trait's private normalizer.
    $processor = makeFormShortcodeProcessor($class);
    $set = $processor->parse(
        $attributes,
        'default',
        (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor(),
        new NullOutput
    );

    // Assert the stable set shape separately from the generated row ID.
    expect($set)->toHaveKeys(['type', 'attrs'])
        ->and($set['type'])->toBe('set')
        ->and(formShortcodeSetAttribute($set, 'id'))->toBeString()->not->toBe('')
        ->and(formShortcodeSetAttribute($set, 'values'))->toBe([
            'type' => 'form',
            'import_wordpress_notes' => $plugin.' form ID '.$note,
        ])
        ->and($processor::getFormIds())->toBe($tracked);
})->with('form shortcode plugins')->with([
    'missing' => [[], 'not specified', []],
    'null' => [['id' => null], 'not specified', []],
    'empty string' => [['id' => ''], 'not specified', []],
    'integer zero' => [['id' => 0], '0', ['0']],
    'string zero' => [['id' => '0'], '0', ['0']],
    'integer' => [['id' => 17], '17', ['17']],
    'numeric float' => [['id' => 3.5], '3.5', ['3.5']],
    'string slug' => [['id' => 'contact-us'], 'contact-us', ['contact-us']],
    'boolean' => [['id' => true], 'not specified', []],
    'array' => [['id' => ['17']], 'not specified', []],
]);

it('builds independent enabled page-builder blocks with the correct plugin notes', function (
    string $class,
    string $plugin,
    string $blockType,
    string $cache
): void {
    // Build twice to detect a constant or missing row ID as well as wrong notes.
    $processor = makeFormShortcodeProcessor($class);
    $assetImporter = (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor();
    $first = $processor->toPageBuilderBlock(['id' => 17], 'default', $assetImporter, new NullOutput);
    $second = $processor->toPageBuilderBlock([], 'default', $assetImporter, new NullOutput);

    // Match the entire block while allowing only the generated identifier to vary.
    expect($first['id'])->toBeString()->not->toBe('')
        ->and($second['id'])->toBeString()->not->toBe($first['id'])
        ->and($first)->toBe([
            'id' => $first['id'],
            'type' => $blockType,
            'enabled' => true,
            'form_notes' => $plugin.' form ID 17',
        ])
        ->and($second)->toBe([
            'id' => $second['id'],
            'type' => $blockType,
            'enabled' => true,
            'form_notes' => $plugin.' form ID not specified',
        ])
        ->and($processor::getFormIds())->toBe(['17']);
})->with('form shortcode plugins');

it('uses only its own plugin handle in Bard sets and page-builder notes', function (
    string $class,
    string $plugin,
    string $blockType,
    string $cache
): void {
    // Seed conflicting handles so a lookup in the wrong plugin cannot pass.
    $processor = makeFormShortcodeProcessor($class);

    foreach (['createdGravityFormsHandles', 'createdFormidableFormsHandles', 'createdNinjaFormsHandles'] as $property) {
        (new ReflectionProperty($processor, $property))->setValue(null, ['17' => 'wrong_plugin']);
    }

    (new ReflectionProperty($processor, $cache))->setValue(null, ['17' => 'contact_form']);

    // Exercise both public render targets with the same mapped reference.
    $assetImporter = (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor();
    $set = $processor->parse(['id' => 17], 'default', $assetImporter, new NullOutput);
    $block = $processor->toPageBuilderBlock(['id' => '17'], 'default', $assetImporter, new NullOutput);

    expect(formShortcodeSetAttribute($set, 'values'))->toBe(['type' => 'form', 'form' => 'contact_form'])
        ->and($block['form_notes'])->toBe('form handle: contact_form')
        ->and($block['type'])->toBe($blockType)
        ->and($processor::getFormIds())->toBe(['17', '17']);
})->with('form shortcode plugins');

it('reads page-builder notes safely from an extended shortcode parser', function (array $set, string $notes): void {
    // Substitute the protected parser while exercising the public block renderer.
    $processor = new class($set) extends GravityFormsShortcodeProcessor
    {
        /**
         * Keep the extension's parsed set for the renderer.
         *
         * @param  array<int|string, mixed>  $set  The parsed set fixture
         */
        public function __construct(private readonly array $set) {}

        /**
         * Return an extension's set without assuming the built-in nested shape.
         *
         * @param  array<int|string, mixed>  $attributes  The shortcode attributes
         * @param  array<int|string, mixed>  $terms  The available terms
         * @return array<int|string, mixed> The extension's set
         */
        protected function parseFormShortcode(
            array $attributes,
            string $site,
            AssetImporter $assetImporter,
            OutputInterface $output,
            array $terms,
            string $pluginName,
            ?string $pageSlug
        ): array {
            return $this->set;
        }
    };

    // Require the documented fallback instead of warnings or malformed notes.
    $block = $processor->toPageBuilderBlock(
        [],
        'default',
        (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor(),
        new NullOutput
    );

    expect($block['form_notes'])->toBe($notes);
})->with([
    'top-level notes' => [['import_wordpress_notes' => 'legacy reference'], 'legacy reference'],
    'empty attrs' => [['attrs' => [], 'import_wordpress_notes' => 'legacy reference'], 'legacy reference'],
    'non-array attrs' => [
        ['attrs' => new ArrayObject(['values' => ['import_wordpress_notes' => 'not an array']]), 'import_wordpress_notes' => 'legacy reference'],
        'legacy reference',
    ],
    'non-array values' => [['attrs' => ['values' => 'invalid'], 'import_wordpress_notes' => 'legacy reference'], 'legacy reference'],
    'missing notes' => [['attrs' => ['values' => []]], 'Gravity Forms form ID not specified'],
    'non-string notes' => [['attrs' => ['values' => ['import_wordpress_notes' => 4]]], 'Gravity Forms form ID not specified'],
    'empty notes' => [['attrs' => ['values' => ['import_wordpress_notes' => '']]], ''],
]);
