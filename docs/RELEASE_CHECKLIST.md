# Release Checklist (Monthly Reports)

**Date:** 02/09/2025  
**Scope:** MVP internal run

## Pre-flight
- [ ] DOMPDF installed (Settings → Satori Audit → PDF Engine → Probe shows Success) or use HTML → Print to PDF.
- [ ] Safelist enforced, and internal recipients added.
- [ ] Access control: only main/allowed admins see the settings.
- [ ] Default PDF orientation suitable (Landscape for wide tables).

## Generate
- [ ] Open Tools → Satori Audit → Preview (HTML).
- [ ] Scan “Versions & Update Dates” for expected weekly lines (single vs multi-week rule).
- [ ] Check plugin table widths (no truncation).

## Export & Send
- [ ] Export PDF (or HTML if DOMPDF absent).
- [ ] Run "Test Email" (dry run → only current admin).
- [ ] Send to safelisted recipients only.
- [ ] Archive a copy under `/samples/` for auditing.

## Post-run
- [ ] Update **docs/CHANGELOG.md** if you made changes.
- [ ] Add action items to **docs/ROADMAP.md** (found issues or improvements).
