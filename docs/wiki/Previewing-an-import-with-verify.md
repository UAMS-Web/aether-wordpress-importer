Written against v0.35.0.

You want to know what an import would do (how many entries, how many redirects, what it would warn about) without changing the application. `--verify` runs the whole import against a temporary copy and prints the same summary lines.

## Steps

1. Check there is room in the system temporary directory. The run copies the content and asset directories there, so it needs about as much space as they take.
2. Run the import you intend, with `--verify` added. For example, redirects from a site read through the migration API:

   ```bash
   php artisan statamic:import:wordpress storage/app/imports/example-site --source=api --network=https://wordpress.example.test --site=3 --content-type=redirect_rule --verify
   ```

3. Read the summary. It prints the counts a real import prints and, for each redirect domain host, the number of entries stored with a `wordpress_post_id`.
4. When the counts are what you expect, run the same command without `--verify`.

## How it works

At the start of the run the Stache stores, the blueprints, fieldsets and forms, and the disks behind the asset containers are copied to a directory under the system temporary directory, and the process is pointed at the copy, with its caches held in memory. Statamic's image cache moves with them. Database writes stay inside a transaction on the default connection and on each connection behind an Eloquent Statamic repository, and that transaction is rolled back. The copy is deleted when the run ends, whether it succeeds or fails. An import writes from too many places, and depends too much on what is already stored, for a run that wrote nothing to report the same counts.

## What it covers, and what it does not

- **The guarantee covers the content and asset directories, and the host's asset metadata directory (`asset_metadata.yaml_directory`).** They are not written to, even if the run fails or is killed. A killed run leaves its copy behind under the temporary directory (`wordpress-import-verify-*`), which is safe to delete.
- **The search index rebuild and the Laravel cache clear are skipped**, since both act on the application rather than on the copy. Bard sanitization and the dataless entry sweep run against the copy.
- **`--source=api` still writes its WXR parts** into the directory the `file` argument names.
- **Automatic Git commits and preset image generation are off for the run, and jobs run synchronously**, so a save queues no commit of the application's repository and leaves nothing for a worker to do later.
- **A database write holds its connection's write lock until the run ends.** On SQLite that blocks other writers to that database for the rest of the run.
- **A setup that cannot be contained is refused**, and nothing is imported: an asset container on a disk that is not local.
- **Listeners the host application registers are not contained.** A listener that writes to its own location on an entry save still runs, and so does a write to a connection the list above does not name.

## Reference

- [Command reference](Command-reference.md): `--verify` and the options the example uses.
