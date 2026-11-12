// cspell:ignore Likert likert vitest Vitest vitejs jsdom
// Vite plugin for Vitest: replaces `@statamic/cms` and `@statamic/cms/ui` with modules that read
// a stub registry (`statamic-harness.js`) instead of the real `__STATAMIC__` global.
//
// The real entries are `export const { Fieldtype, ... } = __STATAMIC__.core`, which only exists
// inside the Control Panel. This plugin generates, per entry, one export for each name the
// add-on's own sources import. A name with no stub makes the registry throw while the module
// evaluates, naming the import, so a new import fails the test instead of rendering nothing.
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';

const ENTRIES = {
    '@statamic/cms': 'core',
    '@statamic/cms/ui': 'ui',
};

const PREFIX = '\0statamic-harness:';

/** Every `.vue` and `.js` file under a directory, test files excluded. */
function sourceFiles(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) {
            return sourceFiles(path);
        }

        return /\.(vue|js)$/.test(name) && !/\.test\.js$/.test(name) ? [path] : [];
    });
}

/** The names imported from one entry across the sources; an alias reports the exported name. */
function importedNames(files, specifier) {
    const pattern = /import\s+(?:[\w$]+\s*,\s*)?\{([^}]*)\}\s*from\s*['"]([^'"]+)['"]/g;
    const names = new Set();

    for (const file of files) {
        for (const [, list, from] of readFileSync(file, 'utf8').matchAll(pattern)) {
            if (from !== specifier) {
                continue;
            }

            for (const part of list.split(',')) {
                const name = part.trim().split(/\s+as\s+/)[0];

                if (name !== '') {
                    names.add(name);
                }
            }
        }
    }

    return [...names].sort();
}

/**
 * @param {{ sources?: string[] }} options Directories, relative to the project root, whose imports
 *   decide which names each entry exports.
 */
export default function statamicStubs({ sources = ['resources/js'] } = {}) {
    return {
        name: 'statamic-stubs',
        enforce: 'pre',

        resolveId(id) {
            return id in ENTRIES ? PREFIX + ENTRIES[id] : null;
        },

        load(id) {
            if (!id.startsWith(PREFIX)) {
                return null;
            }

            const entry = id.slice(PREFIX.length);
            const specifier = Object.keys(ENTRIES).find((key) => ENTRIES[key] === entry);
            const files = sources.flatMap((dir) => sourceFiles(resolve(dir)));
            const names = importedNames(files, specifier);

            return [
                `const registry = globalThis.__STATAMIC_HARNESS__.${entry};`,
                ...names.map((name) => `export const ${name} = registry[${JSON.stringify(name)}];`),
            ].join('\n');
        },
    };
}
