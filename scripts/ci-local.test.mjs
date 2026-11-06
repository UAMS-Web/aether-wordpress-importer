// Tests for ci-local.mjs and the job list it runs (wordpress-importer-archive#1319).
//
// Run: node --test scripts/ci-local.test.mjs
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, realpathSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { parseConfig, primaryRootOf } from '../.claude/skills/local-ci/local-ci.mjs'
import { bootstrapArgs, runnerArgs, slotPath } from './ci-local.mjs'
import { treeKind } from './worktree-slots.mjs'

const ROOT = join(fileURLToPath(new URL('.', import.meta.url)), '..')

describe('runnerArgs', () => {
    it('places a --branch slot beside the primary, under a name worktree-slots reads as runner-owned', () => {
        const out = runnerArgs(['--branch', 'x'], '/p/wordpress-importer')

        assert.deepEqual(out, ['--branch', 'x', '--worktree-path', join('/p', 'wordpress-importer-local-ci')])
        assert.equal(treeKind(out[3], '/p/wordpress-importer'), 'ci')
    })

    it('does not reuse the old runner\'s slot, which the shared runner refuses for want of its marker', () => {
        assert.notEqual(slotPath('/p/wordpress-importer'), join('/p', 'wordpress-importer-ci'))
    })

    it('leaves an in-place run alone, since the runner refuses --worktree-path without --branch', () => {
        assert.deepEqual(runnerArgs(['--dry-run'], '/p/repo'), ['--dry-run'])
    })

    it('keeps a --worktree-path the caller chose', () => {
        assert.deepEqual(runnerArgs(['--branch', 'x', '--worktree-path', '/elsewhere'], '/p/repo'), ['--branch', 'x', '--worktree-path', '/elsewhere'])
    })

    it('translates the private runner\'s -b and --jobs all', () => {
        assert.deepEqual(runnerArgs(['-b', 'x', '--jobs', 'all'], '/p/repo').slice(0, 3), ['--branch', 'x', '--all'])
        assert.deepEqual(runnerArgs(['--jobs', 'pint,spell'], '/p/repo'), ['--jobs', 'dependencies,pint,spell'])
    })

    it('adds the dependencies job to every --jobs subset, since the runner ignores always there', () => {
        const out = runnerArgs(['--branch', 'x', '-j', 'pest,phpstan'], '/p/repo')

        assert.deepEqual(out.slice(0, 4), ['--branch', 'x', '-j', 'dependencies,pest,phpstan'])
        assert.deepEqual(runnerArgs(['--jobs', 'pest,dependencies'], '/p/repo'), ['--jobs', 'pest,dependencies'])
    })

    it('refuses the private runner\'s --fresh and --skip-install, naming what to do instead', () => {
        assert.throws(() => runnerArgs(['--branch', 'x', '--fresh'], '/p/repo'), /--fresh.*git worktree remove/)
        assert.throws(() => runnerArgs(['--skip-install'], '/p/repo'), /--skip-install.*dependencies/)
    })

    it('puts the slot beside the primary when invoked from a linked worktree', () => {
        // `.native` expands a Windows short name such as RUNNER~1, as git does.
        const dir = realpathSync.native(mkdtempSync(join(tmpdir(), 'ci-local-')))
        const git = (cwd, ...args) => {
            const r = spawnSync('git', ['-C', cwd, ...args], { encoding: 'utf8' })
            assert.equal(r.status, 0, r.stderr)
        }

        try {
            const primary = join(dir, 'repo')
            git(dir, 'init', '-q', 'repo')
            git(primary, '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '--allow-empty', '-m', 'x')
            const linked = join(dir, 'repo-a')
            git(primary, 'worktree', 'add', '-q', '--detach', linked)

            // The control: from the linked worktree itself the slot would be `repo-a-local-ci`.
            assert.notEqual(slotPath(linked), slotPath(primary))
            assert.deepEqual(runnerArgs(['--branch', 'x'], primaryRootOf(linked)), ['--branch', 'x', '--worktree-path', slotPath(primary)])
        } finally {
            rmSync(dir, { recursive: true, force: true })
        }
    })
})

describe('bootstrapArgs', () => {
    const slot = '/w/wordpress-importer-local-ci'
    const full = ['--branch', 'x', '--all', '--comment', '--worktree-path', slot]
    const missing = () => false
    const present = () => true

    it('runs the dependencies job alone first when the slot has no vendor/autoload.php', () => {
        assert.deepEqual(bootstrapArgs(full, missing), ['--branch', 'x', '--worktree-path', slot, '--jobs', 'dependencies'])
    })

    it('asks about the slot\'s own autoloader, not the invoking tree\'s', () => {
        const asked = []
        bootstrapArgs(full, (path) => asked.push(path) && false)
        assert.deepEqual(asked, [join(slot, 'vendor', 'autoload.php')])
    })

    it('adds nothing once the slot has vendor/', () => {
        assert.equal(bootstrapArgs(full, present), null)
    })

    it('adds nothing to an in-place run or a dry run', () => {
        assert.equal(bootstrapArgs(['--all'], missing), null)
        assert.equal(bootstrapArgs([...full, '--dry-run'], missing), null)
    })
})

describe('local-ci.json against ci.yml', () => {
    const config = parseConfig(readFileSync(join(ROOT, 'local-ci.json'), 'utf8'))
    const composer = JSON.parse(readFileSync(join(ROOT, 'composer.json'), 'utf8'))
    const ci = readFileSync(join(ROOT, '.github', 'workflows', 'ci.yml'), 'utf8')
    const ciJobs = ci.slice(ci.indexOf('\njobs:')).split('\n').map((line) => /^ {2}([a-z][\w-]*):$/.exec(line)?.[1]).filter(Boolean)
    const jobText = (job) => ci.slice(ci.indexOf(`\n  ${job}:\n`) + 1).split(/\n {2}[a-z][\w-]*:\n/)[0]
    const LOCAL_ONLY = ['dependencies', 'synced-files', 'handed-back-workflow']
    // The aggregate the ruleset requires as `CI` (#163). It runs no command of its own, so it
    // has no local counterpart.
    const AGGREGATE = 'ci'
    // The job that calls the shared chain in UAMS-Web/uams-ci-workflows (#415).
    const CALL = 'checks'
    const chained = ciJobs.filter((job) => job !== AGGREGATE)
    const plain = chained.filter((job) => job !== CALL)
    // Each local job that mirrors a job of the called chain, and the Composer script that chain
    // job runs. `validate` runs a fixed command in the chain, not a script.
    const CHAIN_MIRRORS = { pint: 'test:pint', phpstan: 'stan', rector: 'test:refactor', pest: 'test' }
    const CHAIN_VALIDATE = 'composer validate --no-check-publish --no-check-all'

    it('reads ci.yml\'s jobs, so an empty comparison cannot pass', () => {
        assert.ok(ciJobs.length >= 5, `read ${ciJobs.length} ci.yml jobs`)
        assert.ok(ciJobs.includes(CALL), `ci.yml has no \`${CALL}\` job`)
    })

    it('runs every plain job, every chain job it mirrors, and nothing else but the declared local-only jobs', () => {
        const names = config.jobs.map((job) => job.name)
        const mirrored = [...plain, 'validate', ...Object.keys(CHAIN_MIRRORS)]

        assert.deepEqual(names.filter((name) => !LOCAL_ONLY.includes(name)).sort(), mirrored.sort())
        assert.deepEqual(names.filter((name) => LOCAL_ONLY.includes(name)).sort(), [...LOCAL_ONLY].sort())
    })

    it('selects every job on every run, as the private runner did', () => {
        assert.deepEqual(config.jobs.filter((job) => job.always !== true).map((job) => job.name), [])
    })

    it('runs the synced-files check and the handed-back drift check', () => {
        const run = Object.fromEntries(config.jobs.map((job) => [job.name, job.run]))

        assert.equal(run['synced-files'], 'node .claude/skills/synced-files/scripts/check-synced-files.mjs')
        assert.equal(run['handed-back-workflow'], 'node .claude/skills/handed-back-label/scripts/check-handed-back-workflow.mjs')
    })

    it('runs each mirrored plain command as ci.yml does', () => {
        const run = Object.fromEntries(config.jobs.map((job) => [job.name, job.run]))
        const ciLines = ci.split('\n').map((line) => line.trim())

        for (const name of ['mutate-ignore-breadth', 'node-test', 'python-test', 'changed-tests']) {
            // A whole line, not a substring, so a narrowed command cannot pass.
            assert.ok(ciLines.includes(`run: ${run[name]}`), `${name}: ci.yml does not run \`${run[name]}\``)
        }
    })

    it('runs the shared changed-tests report with history and independent dependencies', () => {
        const own = jobText('changed-tests')
        const job = config.jobs.find((job) => job.name === 'changed-tests')

        assert.equal(job.run, 'node .claude/skills/pest-testing/scripts/changed-tests-against-base.mjs --tests tests/Unit --tests tests/Integration')
        assert.equal(config.base, 'main', 'the local fallback must match the configured base')
        assert.match(own, /^ {10}fetch-depth: 0$/m)
        assert.match(own, /^ {6}- uses: \.\/\.github\/actions\/setup$/m)
        assert.doesNotMatch(own, /--install none|continue-on-error|\|\| true/)
    })

    // The called chain runs Composer scripts from this repository's checkout, so a local command
    // mirrors it when it is that script, with `vendor/bin/` in front of a Composer binary.
    it('runs each chain job\'s Composer script as the chain does', () => {
        const run = Object.fromEntries(config.jobs.map((job) => [job.name, job.run]))

        assert.equal(run.validate, CHAIN_VALIDATE)

        for (const [name, script] of Object.entries(CHAIN_MIRRORS)) {
            const command = composer.scripts?.[script]

            assert.equal(typeof command, 'string', `composer.json has no \`${script}\` script`)
            assert.equal(run[name], command.startsWith('node ') ? command : `vendor/bin/${command}`, `${name} does not run \`composer ${script}\``)
        }
    })

    // A tracked PHP file under a dot-directory is skipped by a bare `pint --test`, so the script
    // the chain's Pint job runs must be the one that names those directories.
    it('lints the dot-directories in the chain\'s Pint job', () => {
        assert.equal(composer.scripts?.['test:pint'], 'node scripts/run-pint.mjs')
    })

    describe('the call to the shared chain', () => {
        const own = jobText(CALL)

        it('pins a release tag, never a branch', () => {
            assert.match(own, /^ {4}uses: UAMS-Web\/uams-ci-workflows\/\.github\/workflows\/ci\.yml@v\d+\.\d+\.\d+$/m)
        })

        // The Windows leg (#186). The chain runs these four on Linux alone unless they are named.
        it('keeps every chain job on Windows', () => {
            const listed = (/^ {6}all-platform-jobs: '([^']*)'$/m.exec(own)?.[1] ?? '').split(/\s+/)

            for (const job of ['composer-validate', 'pint', 'rector', 'phpstan']) {
                assert.ok(listed.includes(job), `${job} is not in all-platform-jobs`)
            }
            assert.doesNotMatch(own, /^ {6}linux-only: true$/m)
        })

        // Deferred to post-launch on #315: no mutation run of this repository finished on a hosted runner.
        it('leaves mutation testing to the local run, as #315 decided', () => {
            assert.match(own, /^ {6}mutate: false$/m)
        })
    })

    describe('the CI aggregate the ruleset requires', () => {
        // The job's own text, from its `ci:` line to the next job or the end of the file.
        const start = ci.indexOf(`\n  ${AGGREGATE}:\n`)
        const rest = ci.slice(start + 1)
        const next = rest.slice(1).search(/\n {2}[a-z][\w-]*:\n/)
        const body = next === -1 ? rest : rest.slice(0, next + 1)
        const needs = (/^ {4}needs:\n((?: {6}- \S+\n?)+)/m.exec(body)?.[1] ?? '').match(/(?<=^ {6}- )\S+/gm) ?? []

        it('is the last job, named CI, so the ruleset context does not move', () => {
            assert.notEqual(start, -1, `ci.yml has no \`${AGGREGATE}\` job`)
            assert.equal(ciJobs.at(-1), AGGREGATE)
            assert.match(body, /^ {4}name: CI$/m)
        })

        it('needs every other job, so a job left off the list is required by nothing', () => {
            assert.ok(chained.length >= 4, `read ${chained.length} chained jobs`)
            assert.deepEqual([...needs].sort(), [...chained].sort())
        })

        it('runs whatever the others did, and is not gated on RUN_REMOTE_CI, which would let it skip and pass', () => {
            assert.match(body, /^ {4}if: always\(\)$/m)
            assert.doesNotMatch(body, /RUN_REMOTE_CI/)

            for (const job of chained) {
                assert.match(jobText(job), /^ {4}if: vars\.RUN_REMOTE_CI != 'false'$/m, `${job} is not gated`)
            }
        })

        // The Windows leg (#186). A matrix keeps one `needs:` entry per job, so this is what
        // fails when a job stops running on Windows: nothing in `needs:` would change.
        it('runs every plain job on both operating systems, each leg reporting', () => {
            assert.ok(plain.length >= 3, `read ${plain.length} plain jobs`)

            for (const job of plain) {
                const own = jobText(job)

                assert.match(own, /^ {4}runs-on: \$\{\{ matrix\.os \}\}$/m, `${job} does not run on the matrix`)
                assert.match(own, /^ {8}os: \[ubuntu-latest, windows-latest\]$/m, `${job} does not list both operating systems`)
                assert.match(own, /^ {6}fail-fast: false$/m, `${job} lets one leg cancel the other`)
                assert.doesNotMatch(own, /^\s*(?:exclude|include):/m, `${job} narrows its matrix`)
                assert.match(own, /^ {4}name: .*\(\$\{\{ matrix\.os \}\}\)$/m, `${job}'s checks do not name their operating system`)
            }
        })

        it('tolerates no failure, on either operating system', () => {
            assert.doesNotMatch(ci.slice(ci.indexOf('\njobs:')), /^[\s-]*continue-on-error:/m)
        })

        it('runs every step in bash, which a Windows runner does not default to', () => {
            assert.match(ci, /^defaults:\n {2}run:\n {4}shell: bash$/m)
        })

        // The step's script, run as the runner would with RESULTS set as `join(needs.*.result, ' ')` sets it.
        const script = (/^ {8}run: \|\n((?: {10}.*\n?)+)/m.exec(body)?.[1] ?? '').replace(/^ {10}/gm, '')
        const run = (results) => spawnSync('bash', ['-e', '-c', script], { encoding: 'utf8', env: { ...process.env, RESULTS: results } })

        it('passes only when every needed job succeeded', () => {
            assert.ok(script.includes('RESULTS'), 'read no script from the CI step')
            assert.equal(run('success success success').status, 0)
        })

        it('fails when it reads no results at all', () => {
            const r = run('')

            assert.equal(r.status, 1)
            assert.match(r.stdout, /No needed job results to read/)
        })

        for (const result of ['failure', 'skipped', 'cancelled']) {
            it(`fails when a needed job reports ${result}`, () => {
                const r = run(`success ${result} success`)

                assert.equal(r.status, 1)
                assert.match(r.stdout, new RegExp(`A needed job reported ${result}`))
            })
        }
    })
})
