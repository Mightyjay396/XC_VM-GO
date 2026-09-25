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
| ---- | ------ | ---- | ------------- | ----- |
