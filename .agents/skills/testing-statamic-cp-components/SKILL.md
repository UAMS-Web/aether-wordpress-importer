---
name: testing-statamic-cp-components
description: >-
  How to mount a Statamic Control Panel Vue component (a fieldtype, or a component built from
  `@statamic/cms/ui`) under Vitest, with a copyable harness: the jsdom environment, the
  development dependencies (`vue`, `@vue/test-utils`, `@vitejs/plugin-vue`), a Vite plugin that
  replaces `@statamic/cms` and `@statamic/cms/ui` with stubs, a setup file holding a
  `FieldtypeMixin` stand-in and stubs for the common UI components, and a worked test. Names what
  the harness can pin (the add-on's own markup, computed state, the values passed to `update()`)
  and what it cannot (how Statamic renders its own components). Activate when writing or fixing a
  Vitest test for a `.vue` file in a Statamic add-on, adding Control Panel component tests to an
  add-on that has none, or a test fails with an `__STATAMIC__` or "has no stub" error.
---
<!--
  Synced from UAMS-Web/uams-claude-skills: shared/.claude/skills/testing-statamic-cp-components/SKILL.md
  Edit it there, not here; the next sync overwrites this copy.
  Delivered to this repository through the profile(s): statamic-package.
-->
<!-- cspell:ignore Likert likert vitest Vitest vitejs jsdom -->

# Testing Statamic Control Panel components under Vitest

A Control Panel fieldtype cannot be imported outside the Control Panel. `@statamic/cms` and
`@statamic/cms/ui` are each `export const { ... } = __STATAMIC__.core` (or `.ui`), a destructuring
of a runtime global that only exists in the booted Control Panel, and the Statamic Vite plugin
leaves those imports to the browser. A test that mounts a component therefore has to supply that
global's contents. This skill ships one harness that does, so every add-on with Control Panel
fieldtypes tests them the same way.

## What the harness can and cannot pin

| Pinned by the harness | Not pinned |
| --- | --- |
| The add-on's own markup, classes and conditional branches | How Statamic renders `Combobox`, `RadioGroup`, `Button` and the rest |
| Computed state and what the component passes to a child as props | Grid, Replicator, Group labels, Bard: anything Statamic renders around the add-on |
| The values passed to `update()` (the `update:value` event) | What a Statamic release changes about its own components or the real mixin |
| That every name imported from `@statamic/cms` and `@statamic/cms/ui` has a stub | That the name still exists in the installed Statamic package |

The stubs are stand-ins with the props and events the add-on's markup uses. A passing test says the
add-on's component behaves against that stand-in, not that Statamic still behaves like it.
Two things cover the gap:

- **The import surface**: a test that reads the installed `@statamic/cms` entries and checks every
  imported name is still exported (`aether-form-settings` has one in
  `resources/js/statamic-imports.test.js`). It catches a dropped export, which this harness cannot.
- **A browser-driven check** against a running Control Panel (Playwright, where the repository
  declares it) for how Statamic renders its own components. The harness does not replace it.

**Which stubs are read from Statamic and which are the harness's own assumption.** Only the
`FieldtypeMixin` stand-in is read from a Statamic bundle (below). The rest are the harness's own
assumptions about the props and events an add-on's markup relies on, written to be driven from a
test: the `Button`, `Input`, `Textarea`, `Select`, `Switch` and `Combobox` stubs, the `Fieldtype`
composition entry (its `use()` returns only `{ expose: {} }`) and `HasInputOptionsMixin`
(`normalizeInputOptions()` accepts an options object, a list of strings or a list of
`{ value, label }` or `{ key, label }`). None of them was compared with the real component, so a
test that passes against one proves the add-on's wiring, not Statamic's rendering.

The `FieldtypeMixin` stand-in was read from the `statamic/cms` v6.38.0 Control Panel bundle
(`resources/dist/build/assets/index-*.js`, 2026-11-10). It is not the real mixin: re-read it when
Statamic's major version changes.

## What to copy

The templates are in `templates/` and end in `.dist` so a whole-tree linter or test runner does not
pick them up. Copy each one without the suffix:

| Template | Copy to | Purpose |
| --- | --- | --- |
| `statamic-stubs-plugin.js.dist` | `resources/js/testing/statamic-stubs-plugin.js` | Vite plugin: resolves `@statamic/cms` and `@statamic/cms/ui` to modules built from the names the add-on's sources import |
| `statamic-harness.js.dist` | `resources/js/testing/statamic-harness.js` | Setup file: the stub registry, `FieldtypeMixin`, UI stubs, `stubCore()` and `stubUi()` |
| `vitest.config.js.dist` | `vitest.config.js` (merge into an existing one) | `vue()` plugin, the stubs plugin, the `jsdom` environment, the setup file |
| `likert-scale-fieldtype.test.js.dist` | beside the component, as `<Component>.test.js` | Worked test of a real fieldtype |
| `statamic-harness-controls.test.js.dist` | `resources/js/testing/statamic-harness.test.js` | Controls for the default stubs: a click, `v-model` on `Input`, `Select`, `Switch` and `Combobox` |

Then install the development dependencies:

```bash
npm install --save-dev vitest jsdom vue @vue/test-utils @vitejs/plugin-vue
```

## How it works

- **The plugin generates each entry's exports from the add-on's own imports.** It reads every `.vue`
  and `.js` file under `resources/js` (tests excluded) and exports one binding per imported name,
  each read from `globalThis.__STATAMIC_HARNESS__`.
- **A name with no stub throws, naming it.** The registry is a proxy: reading a name it does not hold
  raises `Statamic harness: "Combobox" is imported from the ui entry but has no stub`. A new import
  fails the suite with the name, not as an empty element. A static import of a missing name would
  instead be `undefined` and render nothing, which is why the exports are generated rather than
  declared.
- **`vue()` from `@vitejs/plugin-vue`, not `statamic()`.** The plugin `@statamic/cms/vite-plugin`
  rewrites `vue` to `window.Vue`, which exists only in the Control Panel.
- **`FieldtypeMixin` supplies `value`, `meta`, `config`, `handle`, `readOnly`, `isReadOnly`,
  `update()` and `updateMeta()`.** `update(v)` emits `update:value`, so a test asserts
  `wrapper.emitted('update:value')`. `handle` is required: always pass it.
- **UI stubs render their attributes and slots.** Each carries `data-stub="<Name>"` and a
  `data-props` JSON attribute. `RadioGroup` and `Radio` are connected: clicking a `Radio` calls the
  group, which emits `update:modelValue`, so a test drives the DOM rather than the stub's internals.
  `Input` and `Textarea` forward `input` events, and `Select` and `Switch` forward `change` events,
  as `update:modelValue`. `Button` forwards its click as `click`, so a parent's `@click` runs.
- **A control reads its model whichever way the parent bound it.** `:model-value` and `v-model`
  (which compiles to a `modelValue` prop plus an `onUpdate:modelValue` listener) both render.
  `Select` renders its `options` as `<option>` elements. `Combobox` renders one button per option
  through the `option` slot (`data-option="<value>"`), the `selected-option` slot once there is a
  value (the listed option, or `{ value }` for a typed one), and emits `update:modelValue` on a
  click; a test emits `update:open` on it to close it.
- **`core` also stubs `Fieldtype`** (the composition-API entry a `<script setup>` fieldtype uses:
  the mixin's `props` and `emits`) **and `HasInputOptionsMixin`** (`normalizeInputOptions()`).

## Adding a stub

A component the harness does not stub (say `Card`) is added in the setup file when every test
needs it, or per test file with `stubUi()` / `stubCore()`. Static `import` statements run before the
body of a test file, so register the stub from a setup file, or load the component with a dynamic
`import()` after calling it:

```js
import { builders, stubUi } from './testing/statamic-harness.js';

stubUi('Card', builders.plain('Card'));
const { default: Component } = await import('./MyFieldtype.vue');
```

Build the stub from `builders` (`plain`, `button`, `input`, `select`, `toggle`, `combobox`, `group`, `option`) where one has the right shape,
and render only what the add-on's markup reads.

## Controls run on the worked test

Both were run on 2026-11-10 against `LikertScaleFieldtype.vue` from `aether-form-settings` (commit
`e2ec93a`), on Windows 11 with Node under Git Bash. Linux and macOS are not yet measured.

- **A broken component fails the test.** Changing `updateRow` to call `this.update(val)` instead of
  `this.update(likertWithRow(this.value, row, val))` turned the matrix-row test red (1 failed, 3
  passed) and restoring it turned it green (4 passed).
- **An import with no stub fails with its name.** Adding `Combobox` to the component's
  `@statamic/cms/ui` import failed the file at import with the `has no stub` error above; removing
  it passed again.

Re-run both when changing a stub, so a stub that stopped reflecting the component is noticed.

The default stubs' own controls (`statamic-harness-controls.test.js.dist`) were run on 2026-11-11 under
`vitest` 5.1.0 with `jsdom` and `@vue/test-utils` installed fresh in a scratch project (8 tests passed),
on Windows 11 under Git Bash; Linux and macOS are not yet measured. Each of six changes to the
harness turned exactly one test red, and restoring the harness turned all eight green:

| Change to `statamic-harness.js` | Test that failed |
| --- | --- |
| `Button` stops emitting `click` | Button emits click |
| `Input` reads only `:model-value` | Input renders a `v-model` value |
| `Select` renders no `<option>` | Select renders its options |
| `Switch` stops emitting on change | Switch renders a `v-model` boolean |
| `Combobox` options stop emitting on click | Combobox renders the selected-option slot |
| `Combobox` always renders the selected-option slot | Combobox renders the selected-option slot |

## Version pins

| Package | Range | Reason |
| --- | --- | --- |
| `vitest` | `^5.1.0` | Declared by `aether-form-settings` (`^5.0.3`), `aether-multisite-forms` and `aether-redirects` (`^5`). The worked test passed on 5.1.0. |
| `jsdom` | `^29.1.1` | The version `uams-statamic` pins. Passed with `vitest` 5.1.0. `happy-dom` is not measured here; a repository that prefers it changes `environment` and re-runs the controls above. |
| `vue` | `^3.5.43` | Declare it directly. `aether-form-settings` resolved 3.5.43 only through `@statamic/cms`, which breaks the day that dependency drops it. |
| `@vue/test-utils` | `^2.5.1` | The `mount()` and `emitted()` API the worked test uses; `vue` 3.x peer. |
| `@vitejs/plugin-vue` | `^6.0.10` | Compiles `.vue` files under Vitest. `@statamic/cms` depends on `^6.0.0`, so the `statamic()` plugin brings it, but the harness config imports it directly. |

**Drift between add-ons today.** `uams-statamic` pins `vitest` `^4.1.8` and `jsdom` `^29.1.1`; the
`aether-form-settings`, `aether-multisite-forms` and `aether-redirects` add-ons pin `vitest` `^5`
with no DOM environment declared; none declares `vue` or `@vue/test-utils`. An add-on adopting this
harness moves to the ranges above; `uams-statamic`, an application with its own Vitest setup,
decides separately and does not receive this skill.

## The DRY line

This skill owns **mounting a Control Panel component under Vitest**. Where a Statamic add-on keeps
its tests (the Pest suites, the host-bound Feature tests) belongs to
[`statamic-package-conventions`](../statamic-package-conventions/SKILL.md). The general discipline
for testing, and for reading a result only once the test has been shown to fail when it should,
belongs to [`tests`](../tests/SKILL.md) and the
[`an-empty-result-is-not-evidence`](../rule-an-empty-result-is-not-evidence/SKILL.md) rule.
