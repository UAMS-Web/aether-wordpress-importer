---
name: statamic-addon-lifecycle
description: >-
  The lifecycle of one of the organization's Statamic add-ons, from the first commit to the next
  release: starting an add-on from the `UAMS-Web/statamic-addon-template` repository (the human
  steps and the session steps, and the order they run in), moving code out of a host site into an
  add-on without breaking the data other tools wrote, releasing it, reviewing it with Statamic's
  `marketplace-review` skill before it is listed, carrying a change that every add-on needs
  back to the template, and measuring what a new add-on needs after creation. Activate when
  creating an add-on, extracting code from a site into one, cutting an add-on release, preparing a
  listing, checking whether a new add-on is caught up, or when a change in one add-on looks like
  it belongs in every add-on. Working inside an existing add-on (provider, configuration, tests) is
  `statamic-package-conventions`.
---
<!--
  Synced from UAMS-Web/uams-claude-skills: shared/.claude/skills/statamic-addon-lifecycle/SKILL.md
  Edit it there, not here; the next sync overwrites this copy.
  Delivered to this repository through the profile(s): statamic-package.
-->
# Statamic add-on lifecycle

The order of work for an add-on from creation to release, and the points where a person has to act.
The conventions for the code inside an add-on are in `statamic-package-conventions`, which this
skill points at and does not repeat. Statamic's own review of an add-on against its marketplace
guidelines is the `marketplace-review` skill, which is Statamic's text and stays separate from this
house guidance.

Each step below is marked **Session** (a session can do it and ask no one) or **Human** (a person
has to do it, because it needs an account permission, a setting a session cannot reach, or a
decision). A session that reaches a Human step says so in the ticket or pull request and stops at
that step; it does not work around it.

## 1. Starting an add-on

Two lists cover this ground: the "Starting an add-on" and "What the command leaves for a person"
sections of the template's `README.md`, and the steps below. Each step has one owner of its
wording, named in the table. The README owns every command and every list of files to change; this
section owns the order, who acts (Session or Human), the first-commit line and the manifest entry
in this repository. Where a step below and the README differ, the README's text is the one to
follow for the command, and this section is corrected.

| Step | Owner of the wording |
| --- | --- |
| 1. Create the repository | README, section 1 item 1 |
| 2. First commit names the template revision | This skill |
| 3. Rename the placeholders | README, section 1 items 2 to 6 |
| 4. Apply the repository settings and rulesets | README, section 4 |
| 5. Add the add-on to the sync manifest | This skill, and `shared/README.md` in this repository |
| 6. Run the sync | This skill |
| 7. Check what the sync delivered | README, section 3 |
| 8. Run the add-on's own gate | README, section 1 item 6 |
| 9. The steps the settings command leaves | README, section 5 |

1. **Human.** Create the repository from the `UAMS-Web/statamic-addon-template` template
   repository ("Use this template"), in the `UAMS-Web` organization, public. The template copies
   its default branch with no history and no link back, so nothing but the next step records which
   template revision the add-on started from.
2. **Session.** Make the first commit say where it started:
   `Created from UAMS-Web/statamic-addon-template@<short SHA>`, with the template's `main` tip at
   the time of creation. Section 5 depends on this line.
3. **Session.** Rename the placeholders as the README's section 1 lists them, and check that the
   provider is still listed under `extra.laravel.providers`
   (`statamic-package-conventions`, section 2).
4. **Human.** Apply the repository settings and the default-branch rules with the command in the
   README's section 4: plan mode first, then `--apply`, which asks for the owner's yes before the
   first write. The command also sets the `required_checks` values that make the organization
   rulesets require `CI`, `Attribution footer` and `Closing keywords`, so no ruleset is chosen or
   edited by hand. It reads the API only, so it runs from any checkout that holds the
   `repository-settings` skill, and it needs `main` to exist, so it follows the first push. Until
   it is applied, nothing prevents a direct push to `main`. A session may run the plan and report
   it; it does not run `--apply`.
5. **Session.** Add the add-on to `shared/manifest.json` in `UAMS-Web/uams-claude-skills` with the
   same profile set as the existing add-ons (copy the `aether-multisite-forms` entry, including
   its `exclude` for `rules/running-boost-update` and `labels`), and `public: true` with
   `publicReviewed` left unset until the files the target would receive have been read for
   anything internal. `shared/README.md` covers adding a target and the public review.
6. **Human.** Run the sync for the new target. Running the sync pushes branches and opens pull
   requests in the target, so it needs the operator's authorization; a session that has not been
   given it prepares the manifest change and stops.
7. **Session.** After the sync's pull request merges, check what it delivered against the README's
   section 3: the rules and skills, the generated section of `AGENTS.md`, and
   `.github/workflows/synced-checks.yml`, which carries the synced-files and mutation-instrument
   jobs. Nothing is restored by hand: the template's `ci.yml` leaves those two jobs out on
   purpose, and copying either into it makes two jobs report under one name.
8. **Session.** Run the add-on's own gate once, from a clean clone, before the first feature is
   written. A skeleton that does not pass its own gate has a placeholder left over.
9. **Human.** The steps the settings command leaves for a person (the wiki's first page, the tags,
   releases and Packagist, the license) are the README's section 5. What a new add-on needs
   afterwards, and how each need is measured, is section 6 below.

## 2. Moving code out of a host site

The host site and the add-on ship separately, so the order of the change matters more than the
code.

- **Session.** Keep configuration keys and fieldtype handles unchanged. Data written by other
  tools (stored forms, entries, blueprints, saved settings) refers to them by name, and a rename
  strands it.
- **Session.** Register explicitly what the host found by discovery. A host discovers event
  listeners, subscribers and middleware by scanning its own namespace; a package's classes are not
  scanned. Each listener becomes a registration in the provider, and each piece of middleware the
  host attached in `bootstrap/app.php` stays attached there by the host, with the alias the
  provider registers (`statamic-package-conventions`, section 2).
- **Session.** Require the package from the host in the same change that deletes the host's copy.
  A change that deletes the copy first leaves the host broken until the require lands, and one
  that requires first loads two definitions of the same class.
- **Session.** Run the host's own tests against the add-on before the host change merges: the
  procedure is the `host-app-tests` skill, and behavior that only the host's `Feature` tests
  exercise is unverified until it has been run there.
- **Human.** Merge the add-on's pull request and tag it before the host's, since the host pins a
  tagged range and merged work is unreachable until a tag exists
  (`statamic-package-conventions`, section 4).
- The provider, configuration read from the host, version pins and the test split are
  `statamic-package-conventions`; do not copy them into the ticket.

## 3. Releasing

- **Session.** Prepare the release: the version, the notes, and the tag command, following
  `writing-release-notes`. When a release is due is the `cutting-releases` rule.
- **Human.** Cut the release. Tagging and publishing need the operator's authorization (see
  *Who may cut it* in `writing-release-notes`), and a cloud session cannot tag at all.
- **Session.** Say where the host's version bump happens. It is a change to the host's
  `composer.json` constraint and lock, in a pull request in the host repository, and it follows
  the tag. An exact `statamic/cms` pin moves in the add-on first and then in the host
  (`statamic-package-conventions`, section 4).

## 4. Reviewing

Run Statamic's `marketplace-review` skill before an add-on is listed, and before a release that
changes what the listing describes (its name, summary, requirements or features).

- **Session.** Run the skill and record its report on the ticket for the release. The skill reports
  evidence and required changes and does not grant approval.
- **Session.** Each required change becomes a ticket in the add-on's repository. The review never
  edits Statamic's skill: it is Statamic's published text, delivered by the sync and compared with
  the published copy weekly, and a local edit is reverted by the next sync.
- **Human.** Submit the listing. Submission is made from an account on the marketplace.

## 5. Keeping the template current

The template is a snapshot taken at one moment and has no link to the add-ons made from it, so a
change travels in only one direction at a time and has to be carried on purpose.

- **A change from an add-on to the template.** When a change made in one add-on would be needed by
  every add-on (CI, scripts, configuration, the skeleton), open a separate pull request in the
  template for it, in its own change and not bundled with the add-on's feature. **Session.**
- **A change from the template to the add-ons.** When the template changes, read each add-on's
  first commit for its `Created from UAMS-Web/statamic-addon-template@<short SHA>` line. An add-on
  whose starting revision predates the change needs it carried forward; one that started after
  does not. File a ticket in each add-on that needs it, naming the template pull request.
  **Session.**
- **A file that moves into the sync leaves the template.** Once a file is delivered by the sync, a
  copy in the template is a second source that drifts. Remove it from the template in the same
  change, rather than updating it there.
- **Human.** Creating a repository, changing the template repository's settings, and publishing a
  repository are not session steps.

## 6. What a new add-on needs after creation

An add-on is caught up when each need below is met. Each one has a command that reads GitHub over
REST and a result that means it is not met, so the state of an add-on is measured, not remembered.
The list is ordered: the release comes first, because the host pins a tagged range (section 3) and
the later needs are read against a tagged state.

Set `R` to the add-on's repository name. A command that prints a count prints it even when the
count is zero, so read the printed number and not the exit status (`grep -c` exits `1` on zero).
**Session** means a session can meet and measure the need; **Human** means a person has to act,
and the session reports the reading and stops.

1. **A release exists. Human** (cutting it needs the operator's authorization, section 3).

   ```bash
   gh api repos/UAMS-Web/$R/releases'?per_page=1' --jq 'length'
   ```

   Not met: `0`. The command lists releases rather than reading `releases/latest`, which skips
   pre-releases and answers `404` for an add-on that only has pre-releases.

2. **The synced files are current. Session** (dispatching the sync is a **Human** step, section 1
   step 6).

   ```bash
   gh api repos/UAMS-Web/$R/contents/.claude/sync-manifest.jsonc --jq .name
   gh api repos/UAMS-Web/$R/pulls'?state=open&per_page=100' --jq '[.[] | select(.head.ref | test("^sync/uams-claude-skills"))] | length'
   ```

   Not met: the first command fails with `404` (the add-on was never synced), or the second prints
   more than `0` (a sync pull request is open and not yet merged).

3. **The CI pin is the current tag. Session.** The pin is a release tag of the shared workflow
   that `ci.yml` names, and Dependabot does not move it.

   ```bash
   gh api repos/UAMS-Web/$R/contents/.github/workflows/ci.yml --jq .content | base64 -d | grep -oE 'uams-ci-workflows/[^ ]+@v[0-9.]+'
   gh api repos/UAMS-Web/uams-ci-workflows/releases'?per_page=1' --jq '.[0].tag_name'
   ```

   Not met: the tag after the `@` in the first output differs from the second output.

4. **The repository settings are applied. Human** (the README's section 4 applies them; a session
   runs the check).

   ```bash
   node .claude/skills/repository-settings/scripts/check-repository-settings.mjs UAMS-Web/$R
   ```

   Not met: exit `1`, with each departure printed on its own line. Exit `2` means the check did
   not run, which is not a pass.

5. **The shared mutate patches are in use. Session.**

   ```bash
   gh api repos/UAMS-Web/$R/contents/composer.json --jq .content | base64 -d | grep -c 'patches-pest-plugin-mutate'
   ```

   Not met: anything but `2` (the `vcs` repository entry and the `require-dev` line both have to
   be there). The `upstream-patch-lifecycle` skill owns how a patch package is registered.

6. **The mutation job is on. Session.** The template's `ci.yml` passes `mutate: false` until the
   add-on has code a mutator changes, and the input is deleted once it has.

   ```bash
   gh api repos/UAMS-Web/$R/contents/.github/workflows/ci.yml --jq .content | base64 -d | grep -cE '^[[:space:]]+mutate: false'
   ```

   Not met: `1` or more.

7. **The full mutation pass runs weekly. Session.** A scheduled run runs the full pass, so the
   workflow has to carry one weekly schedule and not the template's daily one.

   ```bash
   gh api repos/UAMS-Web/$R/contents/.github/workflows/ci.yml --jq .content | base64 -d | grep -oE "cron: '[^']+'"
   ```

   Not met: no line, or an expression whose fifth field is `*` (it runs daily). A weekly
   expression has a single weekday number there, as in `17 7 * * 1`.

8. **Coverage is full. Session.** The bar is mutation-clean on Linux: no unexplained survivors
   (the `tests` skill, "Bar"). The reading is the `Mutation score` job of the latest completed
   scheduled or manually dispatched run on `main`, which mutates everything `composer mutate`
   covers.

   ```bash
   run=$(gh api repos/UAMS-Web/$R/actions/workflows/ci.yml/runs'?branch=main&status=completed&per_page=20' --jq '[.workflow_runs[] | select(.event == "schedule" or .event == "workflow_dispatch")][0].id')
   gh api repos/UAMS-Web/$R/actions/runs/$run/jobs'?per_page=100' --jq '.jobs[] | select(.name | test("Mutation score")) | [.name, .conclusion] | @tsv'
   ```

   Not met: `$run` is empty (no full pass has run, so the second command reads nothing), or the
   job's conclusion is anything but `success` (`skipped` means needs 6 or 7 are not met either).
   A list of 20 runs that holds no scheduled run reads as empty; say so in the report.

9. **PHPStan is at its top level. Session.**

   ```bash
   gh api repos/UAMS-Web/$R/contents/phpstan.neon.dist --jq .content | base64 -d | grep -E '^[[:space:]]+level:'
   ```

   Not met: any value but `max`, or no line. The `code-quality` skill owns how the level is
   found and raised.

10. **The wiki is written and published.** The template ships `docs/wiki/` as skeleton pages with
    placeholder text, and an add-on keeps them until someone fills them in. The `github-wiki`
    skill says how to plan and write the pages and how the publishing action behaves.

    - **The pages are written. Session.** Every page under `docs/wiki/` is read for the template's
      placeholder text:

      ```bash
      for f in $(gh api repos/UAMS-Web/$R/contents/docs/wiki --jq '.[] | select(.name | test("[.]md$")) | .name'); do
        printf '%s %s\n' "$f" "$(gh api repos/UAMS-Web/$R/contents/docs/wiki/$f --jq .content | base64 -d | grep -ciE 'replace this|addon[-_ ]?template')"
      done
      ```

      Not met: any page with a count above `0`, or a listing that fails with `404` or prints no
      page. Read each hit before rewriting it: a page may name the template on purpose. The count
      shows only that a skeleton page survives, and a skeleton page the pattern does not match is
      still unwritten.
    - **The wiki is published. Human** (it publishes on a release, and the wiki's first page is
      created by hand first, the README's section 5).

      ```bash
      gh api repos/UAMS-Web/$R/actions/workflows/publish-wiki.yml/runs'?per_page=1' --jq '.workflow_runs[0] | [.event, .conclusion] | @tsv'
      ```

      Not met: no run, or a conclusion other than `success`.

A reading of any of these is dated: it describes the repository at the moment it was taken.

## Where this skill is delivered

This skill is routed to the `statamic-package` profile, so every add-on receives it. Two other
kinds of repository do not, and the reason is recorded here so the omission is not mistaken for an
oversight:

- **Host sites (the `statamic-app` profile) do not receive it.** The work in section 2 starts in a
  host, but the steps that matter run on the add-on's side, and a host's session finds the
  extraction in the tickets of the epic that moves the code. A host that wants the skill adds the
  profile to its manifest entry.
- **The template repository is not a sync target.** It carries no synced skills, so the skill is
  read from an add-on or from this repository.
