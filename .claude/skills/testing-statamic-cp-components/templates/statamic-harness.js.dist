// cspell:ignore Likert likert vitest Vitest vitejs jsdom
// Vitest setup file: the stub registry behind `@statamic/cms` and `@statamic/cms/ui`.
//
// Add `setupFiles: ['resources/js/testing/statamic-harness.js']` to the Vitest config, and the
// `statamicStubs()` plugin beside it. A component mounted under test then imports these stubs
// where the Control Panel would supply the real ones.
//
// What a stub is: a stand-in with the props and events the add-on's markup uses. It does not
// reproduce how Statamic renders or behaves. Add a stub for a new import with `stubUi()` or
// `stubCore()` in a test, or add it to the lists below when every add-on test needs it.
import { defineComponent, h, inject, provide } from 'vue';

/** The registry for one entry: reading a name that has no stub throws, naming it. */
function registry(entry, stubs) {
    return new Proxy(stubs, {
        get(target, name) {
            if (typeof name === 'symbol' || name in target) {
                return target[name];
            }

            throw new Error(
                `Statamic harness: "${String(name)}" is imported from the ${entry} entry but has no stub. `
                + `Add one with ${entry === 'core' ? 'stubCore' : 'stubUi'}("${String(name)}", component) `
                + 'in the test file or in statamic-harness.js.',
            );
        },
    });
}

/**
 * Stand-in for Statamic's fieldtype mixin: the props, `update()`, `updateMeta()` and
 * `isReadOnly` a fieldtype component reads. `update()` emits `update:value`, as the real mixin does.
 * Measured against the `statamic/cms` v6.38.0 Control Panel bundle (2026-11-10).
 */
export const FieldtypeMixin = {
    emits: ['update:value', 'update:meta', 'focus', 'blur', 'replicator-preview-updated'],
    props: {
        value: { required: true },
        config: { type: Object, default: () => ({}) },
        handle: { type: String, required: true },
        meta: { type: Object, default: () => ({}) },
        readOnly: { type: Boolean, default: false },
        showFieldPreviews: { type: Boolean, default: false },
        namePrefix: String,
        fieldPathPrefix: String,
        metaPathPrefix: String,
        id: String,
    },
    computed: {
        isReadOnly() {
            return this.readOnly
                || this.config.visibility === 'read_only'
                || this.config.visibility === 'computed'
                || this.config.read_only === true;
        },
    },
    methods: {
        update(value) {
            this.$emit('update:value', value);
        },
        updateMeta(value) {
            this.$emit('update:meta', value);
        },
    },
};

/** A component that renders its attributes (as `data-props`) and default slot. */
function plain(name, tag = 'div') {
    return defineComponent({
        name: `Stub${name}`,
        inheritAttrs: false,
        render() {
            return h(
                tag,
                { 'data-stub': name, 'data-props': JSON.stringify(this.$attrs) },
                this.$slots.default?.(),
            );
        },
    });
}

/** A text-like control: renders an element carrying its value and forwards typing as `update:modelValue`. */
function input(name, tag = 'input') {
    return defineComponent({
        name: `Stub${name}`,
        inheritAttrs: false,
        emits: ['update:modelValue'],
        render() {
            const { 'model-value': modelValue, ...rest } = this.$attrs;

            return h(tag, {
                'data-stub': name,
                'data-props': JSON.stringify(rest),
                value: modelValue,
                onInput: (event) => this.$emit('update:modelValue', event.target.value),
            });
        },
    });
}

const GROUP = Symbol('statamic-harness-group');

/** A group: renders its slot and hands its `update:modelValue` emitter to the options inside. */
function group(name) {
    return defineComponent({
        name: `Stub${name}`,
        inheritAttrs: false,
        emits: ['update:modelValue'],
        setup(props, { emit }) {
            provide(GROUP, (value) => emit('update:modelValue', value));
        },
        render() {
            const { 'model-value': modelValue } = this.$attrs;

            return h(
                'div',
                {
                    'data-stub': name,
                    'data-props': JSON.stringify(this.$attrs),
                    'data-model-value': JSON.stringify(modelValue ?? null),
                },
                this.$slots.default?.(),
            );
        },
    });
}

/** An option in a group: renders as a button and reports its value to the group when clicked. */
function option(name) {
    return defineComponent({
        name: `Stub${name}`,
        inheritAttrs: false,
        setup() {
            return { select: inject(GROUP, () => {}) };
        },
        render() {
            const { label, value } = this.$attrs;

            return h(
                'button',
                {
                    type: 'button',
                    'data-stub': name,
                    'data-props': JSON.stringify(this.$attrs),
                    'data-value': JSON.stringify(value ?? null),
                    disabled: this.$attrs['read-only'] === true || undefined,
                    onClick: () => this.select(value),
                },
                label,
            );
        },
    });
}

const stubs = {
    core: { FieldtypeMixin },
    ui: {
        Badge: plain('Badge', 'span'),
        Button: plain('Button', 'button'),
        Card: plain('Card'),
        Description: plain('Description', 'p'),
        Field: plain('Field'),
        Heading: plain('Heading', 'h2'),
        Input: input('Input'),
        Label: plain('Label', 'label'),
        Radio: option('Radio'),
        RadioGroup: group('RadioGroup'),
        Select: input('Select', 'select'),
        Switch: plain('Switch'),
        Textarea: input('Textarea', 'textarea'),
    },
};

globalThis.__STATAMIC_HARNESS__ = {
    core: registry('core', stubs.core),
    ui: registry('ui', stubs.ui),
};

/** Add or replace a `@statamic/cms` stub for the current test file. Static imports run first, so call it from a setup file or before a dynamic `import()` of the component. */
export function stubCore(name, component) {
    stubs.core[name] = component;
}

/** Add or replace a `@statamic/cms/ui` stub for the current test file. Static imports run first, so call it from a setup file or before a dynamic `import()` of the component. */
export function stubUi(name, component) {
    stubs.ui[name] = component;
}

/** The stub builders, for a stub that needs the same shape as a default one. */
export const builders = { plain, input, group, option };
