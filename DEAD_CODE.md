# Dead code index

Functions/methods with **no call sites** anywhere under `src/` (verified by grep,
excluding `vendor/`). They are **commented out, not deleted** — kept as a record
pending a decision to remove for good.

How each entry was verified:

```bash
grep -rn '<name>' src/ --include='*.php' | grep -v vendor
# returns only the definition → 0 call sites
```

When you edit a file listed here, either delete its dead block or re-confirm it
is still unused and leave a note.

| File | Symbol | Refs | Commented out | Notes |
|------|--------|------|---------------|-------|
| `src/Core/Util/ImageUtils.php` | `resize(string $rURL, int $rMaxW, int $rMaxH)` | 0 | 2026-09-13 | `resize.php?url=` in views is a separate public endpoint, not this method. |
| `src/Core/Util/ImageUtils.php` | `generateThumbnail(string $rImage, int $rType)` | 0 | 2026-09-13 | Also had a pre-existing unreachable 32×64 branch (type 5 matched earlier); the whole method is unused regardless. |
