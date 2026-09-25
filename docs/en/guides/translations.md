# Adding a Custom Language

XC_VM uses a file-based translation system. Each language is a single `.ini` file in the `src/Core/Localization/lang/` directory. Adding a new language requires no code changes — just create a file and it will appear in the admin panel automatically.

## Quick Start

1. Generate a machine-translated starting point from `en.ini`:

```bash
make lang-translate LANG_TRANSLATE=xx
```

Replace `xx` with the [ISO 639-1](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes) language code (e.g., `it` for Italian, `pl` for Polish, `ja` for Japanese). The file does not have to exist yet — it is created with every key from `en.ini`. See [Keeping translations in sync](#keeping-translations-in-sync).

To translate by hand instead, copy the English file as a template:

```bash
cp src/Core/Localization/lang/en.ini src/Core/Localization/lang/xx.ini
```

2. Open `xx.ini` and review or translate the values (right side of `=`):

```ini
[Language]
a_to_z = "A to Z"          ; ← translate this
access_code = "Access Code" ; ← translate this
actions = "Actions"         ; ← translate this
```

3. Go to **Settings → Interface → Interface Language** and select the new language code.

That's it. No restart required.

## File Format

Each `.ini` file follows this structure:

```ini
[Language]
key = "Translated text"
another_key = "Another translated text"
```

**Rules:**

- The `[Language]` section header is **required** on the first line.
- Keys are `snake_case` identifiers — **do not change them**.
- Values must be enclosed in **double quotes**; a literal quote inside a value is written as `\"`.
- Keep placeholders such as `{bin}`, `{count}` and `%s`, and any HTML tags, exactly as they are in `en.ini`.
- Keys follow the order and `;` comment sections of `en.ini`.
- The file encoding must be **UTF-8** (without BOM).

## How It Works

| Step | What happens |
|------|-------------|
| Panel boot | `Translator::init()` scans `src/Core/Localization/lang/` for `*.ini` files |
| Language list | `Translator::available()` returns all found language codes |
| User selection | Language is stored in a `lang` cookie (per browser) and in the `settings.language` DB column (global default) |
| Missing key | If a translation key is used in code but missing from your `.ini` file, the system **automatically appends** it with the English value from `en.ini` (or the key name if `en.ini` lacks it too) |

## Available Languages

| Code | File |
|------|------|
| `ar` | `ar.ini` — Arabic |
| `bg` | `bg.ini` — Bulgarian |
| `de` | `de.ini` — German |
| `en` | `en.ini` — English (reference) |
| `es` | `es.ini` — Spanish |
| `fr` | `fr.ini` — French |
| `pt` | `pt.ini` — Portuguese |
| `ru` | `ru.ini` — Russian |

## Keeping translations in sync

`en.ini` is the source of truth. Developers add new UI strings to `en.ini` **only**; the other languages are brought up to date with:

```bash
make lang-translate                      # every <lang>.ini except en
make lang-translate LANG_TRANSLATE=ru    # a single language
```

This runs `tools/i18n/translate.py --ini`, which rebuilds each language file in `en.ini` order:

| Case | Result |
| --- | --- |
| Key translated already | Kept as is |
| Key missing from the file | Machine-translated from `en.ini` |
| Value still identical to the English text (e.g. auto-appended at runtime) | Machine-translated |
| Key no longer in `en.ini` | **Removed** (listed in the output) |

- Placeholders (`{bin}`, `%s`), HTML tags, entities (`&mdash;`) and escaped quotes are never sent to the translation engine, so they come back unchanged.
- The engine is selected with `DOCS_TRANSLATE_PROVIDER`, the same as for the docs: `translators` (default, free web engines, no API key), `anthropic` (needs `ANTHROPIC_API_KEY`) or `noop` (copies English; a quick way to add missing keys and prune stale ones without translating).
- Translations are cached in `build/docs-cache/ini-<lang>.json`: a repeat run makes no network calls, and an interrupted run (Ctrl+C) resumes where it stopped. If the engine fails for a key, the English value is kept and retried on the next run.
- Machine translation is a starting point. Review the `git diff` of the language files before committing, and fix wording by editing the translated value — a value that differs from English is never overwritten.

## Tips

- **Always use `en.ini` as the source of truth** — it contains all keys. Other files may have missing keys that get auto-filled at runtime.
- **Auto-creation of missing keys**: if your file is missing a key, `Translator` appends it with the English value. `make lang-translate` then translates these entries.
- **Validate your file** — make sure `parse_ini_file()` can read it:

```bash
php -r "var_dump(parse_ini_file('src/Core/Localization/lang/xx.ini', false, INI_SCANNER_RAW));" | head -20
```

## Contributing Translations

To contribute a translation to the project:

1. Fork the repository.
2. Create your language file as described above.
3. Submit a Pull Request with the new `.ini` file.

Please ensure all keys from `en.ini` are present and translated.

## Related files

| File | Role |
| --- | --- |
| `src/Core/Localization/Translator.php` | Translation lookup |
| `src/Core/Localization/lang/` | Language files |
| `tools/i18n/translate.py` | Language-file sync (`--ini`) and docs translation |
