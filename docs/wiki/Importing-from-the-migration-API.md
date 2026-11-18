Written against v0.35.0.

You want to import content a WXR export cannot carry, such as site settings and current Find-a-Doc values, by reading a WordPress site directly through the [UAMS migration API plugin](https://github.com/UAMS-Web/uamswp-migration-api). **Skip this page if you are importing an export file**; [Your first import](Your-first-import.md) covers that.

You need the plugin active on the WordPress side, a credential issued there, and that credential pasted into the import when it runs. **Nothing is stored in this application** (step 3).

The plugin accepts two credentials on the same header. **A per-network token, issued from the plugin's own admin page, is the one this migration uses** (decided on [`UAMS-Web/uamswp-migration-api-archive#140`](https://github.com/UAMS-Web/uamswp-migration-api-archive/issues/140)). The shared constant in step 2 still authenticates and is not deprecated; it is the older path and the simpler one for a single local site you control. Both ride the same header, so nothing on this side changes between them.

- [1. Issue a token, on the WordPress side](#1-issue-a-token-on-the-wordpress-side)
- [2. Or define the shared secret](#2-or-define-the-shared-secret)
- [3. Put nothing in this application](#3-put-nothing-in-this-application)
- [4. Check it worked, with three requests](#4-check-it-worked-with-three-requests)
- [5. Run the import](#5-run-the-import)
- [Rules for the credential](#rules-for-the-credential)

## 1. Issue a token, on the WordPress side

The page is registered with the same capability expression the API itself uses, so the two cannot drift:

| | Menu | Capability |
| --- | --- | --- |
| Multisite | **Network Admin, Settings, Migration API token** | `manage_network_options` |
| Single site | **Settings, Migration API token** | `manage_options` |

Press **Generate token**. The value is shown **once** (*"Copy this token now. It is not shown again and is not recoverable."*) as 64 hex characters, beside a short **identifier** that names the token afterward without revealing it. The plugin stores only a hash, so a lost token is regenerated rather than recovered.

**Regenerating keeps the previous token working for an hour by default** (`Token::ROTATION_WINDOW`, 3600 seconds), and the page names the old identifier and when it stops being accepted. The duration is filterable: the plugin passes it through `uamswp_migration_api_token_rotation_window`, and a site that filters it to zero removes the overlap entirely, so read the page rather than assuming the hour.

## 2. Or define the shared secret

For a single local site, or where there is no admin page to reach, the plugin also accepts a constant:

```bash
openssl rand -hex 32
```

It is a **single value, generated once and placed on the WordPress side only.** The importer never stores a copy; you paste it when a run asks. If what you paste differs from what is defined by one character, every request fails.

In that site's `wp-config.php`, **above** the `/* That's all, stop editing! Happy publishing. */` line:

```php
define( 'UAMSWP_MIGRATION_API_SECRET', 'the-value-you-just-generated' );
```

**Do only one of steps 1 and 2.** Either credential authenticates on its own; issuing a token does not disable the constant, and defining the constant does not revoke a token.

## 3. Put nothing in this application

**The address and the credential are not configured here, and there is no step that adds them.** An import asks for both when it starts:

```text
Which network? Paste its WordPress address, including https://
  Reading: https://wordpress.example.test
Paste that network's migration API key
```

The key is typed hidden and exists only for the length of the run. **No option carries the key as a value**, deliberately: that would put a live credential in shell history and in the process list, where it outlives the run.

**Read the echoed address before pasting the key.** It is the only thing in the run that names the network, and `--site` cannot serve that purpose: a blog id is unique only within its own network, so the same id names a different site on each. Each network issues its own key, which is why nothing is stored: configuration able to address every network would hold a live credential per network on disk for a job that reads one network at a time.

**One setting is still host-app configuration**, because it is neither a credential nor a network identity:

```env
UAMSWP_MIGRATION_API_PER_PAGE=100
```

```php
'migration_api' => [
    'per_page' => env('UAMSWP_MIGRATION_API_PER_PAGE', 100),
],
```

It is optional and defaults to 100; the API clamps it to its own maximum of 200, advertised on the index endpoint.

### Passing the key without a terminal

For a run with no terminal attached, `--key-from-stdin` reads the key from the first line of standard input, which is neither shell history nor the process list. It requires `--network`, because a key pasted for one network must not be sent to whichever address a prompt would otherwise have asked for.

To hold a key in a shell without it reaching history, read it rather than assigning it, so the value arrives as input instead of as an argument:

```bash
printf 'Key: '; read -rs KEY; echo
printf '%s' "$KEY" | php artisan statamic:import:wordpress storage/app/imports/example-site --source=api --network=https://wordpress.example.test --key-from-stdin
```

**The shell you pipe from decides whether the key arrives intact.** The reader strips a trailing `\r\n`, so a trailing newline is not a problem, but it does not strip a byte-order mark, and one common shell adds one. Measured on Windows 11 against a fixture of exactly 8 bytes:

| Shell | Form | Delivered |
| --- | --- | ---: |
| Git Bash | `printf '%s' "$KEY"` | 8, exact |
| `cmd.exe` | `< file` redirect | 8, exact |
| `cmd.exe`, pwsh 7 | `echo` into a pipe | 10: trailing `0d0a`, trimmed by the reader |
| **PowerShell 5.1** | pipe | **13: a leading three-byte BOM (`U+FEFF`)**, which is *not* trimmed |

**Use `printf '%s'` from bash, or a file redirect.** A key piped from PowerShell 5.1 arrives with three invisible leading bytes, and setting `$OutputEncoding` to UTF-8 without a BOM **does not remove them**. The symptom is a `401`, the same `401` the API returns for an unset constant, a wrong key and a correctly refused anonymous request.

## 4. Check it worked, with three requests

**Every failure here looks identical**: an unset secret, a mismatched secret, a typo and a correctly refused anonymous request all return the same `401`. A single passing request cannot tell you the secret is actually being *checked*.

```bash
API="https://wordpress.example.test/wp-json/uamswp-migration/v1"
printf 'Key: '; read -rs SECRET; echo

curl -s -o /dev/null -w 'correct key: %{http_code}\n' -H "X-UAMSWP-Migration-Key: $SECRET" "$API/"
curl -s -o /dev/null -w 'no key:      %{http_code}\n' "$API/"
curl -s -o /dev/null -w 'wrong key:   %{http_code}\n' -H "X-UAMSWP-Migration-Key: nope" "$API/"
```

Expect **200**, **401**, **401** in that order. The wrong-key line is the important one: without it, a broken guard that accepted any header would still look correct.

**`read` rather than `SECRET=...` is the point of the second line, not a style choice.** An assignment puts the credential in your shell history. Reading it means the command is recorded and the key is not; `-s` keeps it off the screen, and the `echo` supplies the newline that `-s` suppressed.

**The prompt is a separate `printf` on purpose; do not fold it into `read -p`.** That flag prompts in bash and means *read from a coprocess* in zsh, the macOS default login shell: the variable is never assigned, `curl` sends an empty key, and the API answers the same `401` this step exists to tell apart. The split form was measured working in both shells.

**Warning:** if the first line fails with `SSL certificate problem: self-signed certificate`, the site is a local `.test` host, and `curl -k` skips the check. Add it only for a local host you control, never for a real one: the first request carries the secret, and without certificate verification nothing has authenticated whoever receives it.

## 5. Run the import

```bash
php artisan statamic:import:wordpress storage/app/imports/example-site --source=api
```

With `--source=api` the `file` argument is the directory the run writes the WXR it reads into. `--site` picks a site within the network and `--modified-after` reads only recently changed posts; both are on [Command reference](Command-reference.md#options). From here the run asks the same questions as [Your first import](Your-first-import.md).

## Rules for the credential

- **Never commit it**, and never paste it into a ticket, pull request, commit message or chat. A token's **identifier** is the safe thing to quote: it names which credential without being one.
- **Never print it.** `grep -n` on `wp-config.php` puts it in your terminal history and in any log capturing that output. Use `grep -c` to check only whether it is set.
- **Rotation differs between the two.** Regenerating a **token** keeps the previous one accepted for an hour by default, so the consumer can be updated without a refused request in between; the page's stated expiry is the authority rather than the number here. The **shared constant** has no grace period: both copies change together, and requests in flight during the edit fail.
- **Remove it when the migration is done.** The plugin is explicitly temporary.

The full procedure, including how to place the value without any person or tool seeing it, is the [migration API secret runbook](https://github.com/UAMS-Web/aether-wordpress-importer/blob/main/docs/runbooks/migration-api-secret.md). The API's endpoints, pagination and query semantics are in the [migration API contract](https://github.com/UAMS-Web/aether-wordpress-importer/blob/main/docs/migration-api-contract.md).

## Reference

- [Command reference](Command-reference.md): `--source`, `--network`, `--key-from-stdin`, `--site`, `--modified-after` and `--minerva-generation`.
