# Magento 2: Atomic SRI hashes storage (`sri-hashes.json`)

Small Magento 2 module that replaces `Magento\Csp\Model\SubresourceIntegrity\StorageInterface` with an implementation that:

1. **Writes safely** — Full payload is written to a unique temp file next to `sri-hashes.json`, then published with a **single `rename()`** so the live file is not a growing half-write, and readers do not see a gap where the target was deleted before the new file appears (Linux: atomic replace on the same filesystem).
2. **Recovers from corruption** — On `load()`, if the file is not valid JSON, it is **removed** after logging so the next request can rebuild instead of breaking checkout with a parse error.

## Background / issue

Magento’s CSP module stores Subresource Integrity hashes in `pub/static/**/sri-hashes.json` (and adminhtml). Under **concurrent storefront traffic** (checkout, etc.), the stock file storage can leave **corrupt or partial JSON** (for example concatenated objects or truncated files). That can surface as **checkout failures** or 500s when the integrity map cannot be read.

Community write-ups and patches describe the race and related CSP/SRI behavior, for example:

- [SRI hashes / CSP storage race (gist overview and patches)](https://gist.github.com/hryvinskyi/3df14e684d67bd8f7d278f4340ab133b)

This module does **not** replace Adobe’s optional vendor patches for other CSP/SRI edge cases (such as minified bundle path handling). It focuses on **durable, atomic publication** of the JSON file and **self-healing load** when the file is already bad on disk.

## Requirements

- Magento **2.4.x** with **`Magento_Csp`** enabled and SRI/hash storage using `StorageInterface` (typical CSP setups that write `sri-hashes.json`).
- **`pub/static` writable** on nodes that generate or update SRI hashes at runtime (same as core behavior).

## Installation

### From a copy of this folder (app/code)

1. Place the module under `app/code/SeismicPixels/SriHashesAtomicStorage/` (this repository path matches that layout).
2. Enable the module and update the DI graph:

   ```bash
   bin/magento module:enable SeismicPixels_SriHashesAtomicStorage
   bin/magento setup:upgrade
   bin/magento setup:di:compile   # production mode
   ```

3. Clear caches / redeploy as you normally would after a module change.

4. If `app/etc/config.php` is committed in your project, add `'SeismicPixels_SriHashesAtomicStorage' => 1` to the `modules` array (or rely on `module:enable`, which updates it when that file is writable).

### Via Composer (path repository)

If this tree is its own Git repository, you can require it with a [path repository](https://getcomposer.org/doc/05-repositories.md#path):

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "extensions/SriHashesAtomicStorage"
    }
  ],
  "require": {
    "seismicpixels/magento2-module-sri-hashes-atomic-storage": "*"
  }
}
```

Adjust `url` to where you cloned the package. Run `composer update`, then enable the module as above.

### Conflicts

- Remove any other **preference** for `Magento\Csp\Model\SubresourceIntegrity\StorageInterface` (for example a duplicate in another custom module) so only one implementation applies.
- If you previously shipped the same logic under another module name, **disable the old module** or remove its `di.xml` preference to avoid two modules fighting for the same interface.

## License

Use and modify at your own discretion; align the license with your publishing policy when you open-source the repo (for example MIT or OSL-3.0 to match Magento ecosystem norms).

## Namespace / Packagist

The code uses the `SeismicPixels` vendor prefix. For your own GitHub project you may rename the module and PHP namespace in a single pass (`SeismicPixels` → `YourVendor`) and adjust `registration.php`, `etc/module.xml`, `etc/di.xml`, and the `AtomicFile` namespace accordingly.
