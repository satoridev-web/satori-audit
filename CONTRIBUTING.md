# Contributing to Satori Audit

Thanks for helping! This project will move fast; here’s a lightweight flow while we race to MVP.

## Branching & versions
- Use semantic versions: `MAJOR.MINOR.PATCH` (e.g., 3.7.4).
- New work off `main` in short-lived branches: `feat/<slug>` or `fix/<slug>`.
- Bump version in code and **docs/CHANGELOG.md** together.

## Commits
- Conventional format recommended:
  - `feat: weekly label toggle (ordinal/calendar)`
  - `fix: safelist clearing on save`
  - `chore: update tech brief`

## Pull Requests (even if local)
- Include a short “why” and testing notes.
- Attach a sample export (HTML/PDF) under `/samples/` for UI-affecting changes.
- Update **ROADMAP.md** if scope shifts.

## Testing checklist (quick)
- PDF export (portrait & landscape).
- HTML preview width: plugin table does not overflow.
- Weekly lines: single-week vs multi-week behavior.
- Safelist enforcement: only intended recipients pass.
- Access control: only allowed admins see settings.
- DOMPDF installer: upload → probe works with helpful errors.

## Release steps (MVP)
1. Confirm DOMPDF installed or HTML preview looks good.
2. Ensure **landscape** for wide tables (unless you customize columns).
3. Generate monthly HTML/PDF for target sites.
4. Sanity-check “Updated On” fields and bottleneck hints.
5. Send internal review email (test mode) → finalize send to safelisted recipients.
