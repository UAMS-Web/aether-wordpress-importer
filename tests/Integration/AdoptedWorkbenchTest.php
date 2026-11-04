<?php

declare(strict_types=1);

use Statamic\Contracts\Forms\Form;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;
use Statamic\Facades\Form as FormFacade;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\AdoptedWorkbenchCase;

uses(AdoptedWorkbenchCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Pins that an adopted per-process workbench is emptied (issue wordpress-importer-archive#827).
 *
 * `isolateWorkbench()` empties `workbench-<pid>/{forms,blueprints}` rather than
 * adopting them. Without this file that emptying is a surviving mutant: deleting both
 * `removeContents()` calls leaves the whole suite byte-identical, because
 * `PerProcessWorkbenchTest` asserts only where the directories point, never what is
 * in them.
 *
 * @command  php vendor/bin/pest tests/Integration/AdoptedWorkbenchTest.php
 */
it('empties a workbench left behind by a killed run', function (): void {
    expect(AdoptedWorkbenchCase::$plantsLanded)->toBeTrue();

    expect(\file_exists(AdoptedWorkbenchCase::$plantedForm))->toBeFalse();
    expect(\file_exists(AdoptedWorkbenchCase::$plantedBlueprint))->toBeFalse();
});

it('does not resolve a form the killed run left behind', function (): void {
    expect(AdoptedWorkbenchCase::$plantsLanded)->toBeTrue();

    // ---
    // The reason the emptying matters, stated as the reader meets it: a stale file
    // resolves as a real form. That is the inverse of the concurrent-delete race —
    // `Failed asserting that an instance of Statamic\Forms\Form is null` rather than
    // the reverse — and it is what an adopted workbench actually produces.
    // ---
    $handles = [];

    foreach (FormFacade::all() as $form) {
        if ($form instanceof Form) {
            $handles[] = $form->handle();
        }
    }

    expect($handles)->not->toContain('left_by_a_killed_run');
});

it('empties a search index left behind by a killed run', function (): void {
    expect(AdoptedWorkbenchCase::$plantsLanded)->toBeTrue();

    // ---
    // An undecodable `cp.json` adopted here would fail every save in the process with
    // a `JsonException` (#111), so the file is gone and a save succeeds.
    // ---
    expect(\file_exists(AdoptedWorkbenchCase::$plantedSearchIndex))->toBeFalse();

    $collection = CollectionFacade::make('adopted_search_probe');
    $collection->save();

    $entry = Entry::make();

    if (! $entry instanceof StatamicEntry) {
        throw new RuntimeException('Entry::make() did not return a Statamic entry.');
    }

    $entry->collection('adopted_search_probe');
    $entry->slug('saved_after_adoption');
    $entry->data(['title' => 'Saved After Adoption']);
    $entry->save();

    expect(\json_decode((string) \file_get_contents(AdoptedWorkbenchCase::$plantedSearchIndex), true, 512, JSON_THROW_ON_ERROR))
        ->toBeArray()
        ->not->toBeEmpty();
});
