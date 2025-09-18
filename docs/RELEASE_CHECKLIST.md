# Release Checklist — SATORI Audit

This checklist is to be followed for every tagged release to ensure quality and consistency.

---

## Pre-flight

- [ ] Verify version number bump across:
  - `satori-audit.php` header
  - `docs/CHANGELOG.md`
  - Any Tech Briefs if updated
- [ ] Ensure `docs/ROADMAP.md` is updated to reflect:
  - Recently delivered items (latest release).
  - Upcoming milestones (next planned release).
- [ ] Confirm `ROADMAP.md` and `PROGRESS-REPORTS/` folder are consistent.
- [ ] Ensure `docs/RELEASE_CHECKLIST.md` itself is current.
- [ ] Confirm DOMPDF packaged ZIP is still valid and functional.
- [ ] **Cross-check that `docs/CHANGELOG.md` matches the intended GitHub Release draft**
      (section titles, fixes, improvements, Known Issues).

---

## Generation & Testing

- [ ] **Audit JSON Refresh**: Run _Audit Now_ and confirm:
  - JSON export updates immediately.
  - `satori_audit_v3_json_last_generated` timestamp updates in DB.
  - Blue info notice shows “Last generated at …”.
  - Green success notice appears.
  - Audit Log records “Audit JSON refreshed”.
- [ ] Export sample **HTML Preview** and **PDF**:
  - Table width is correct (no overflow).
  - Orientation and page size settings apply correctly.
- [ ] Export **CSV (Plugins)** and check formatting.
- [ ] Verify **Markdown/JSON exports** still generate without errors.
- [ ] Confirm all notices are styled and dismissible.
- [ ] Check safelist enforcement: only allowed recipients pass test mode.
- [ ] Validate **access control**: restricted pages hidden from non-allowed admins.
- [ ] Verify scheduler (daily/weekly/monthly) still triggers.
- [ ] Confirm timestamps/log entries use `wp_date()` (local site timezone).

---

## Exports & Review

- [ ] Save latest **sample-report.html** under `/docs/samples/`.
- [ ] Attach updated sample PDF export to the Pull Request / Release.
- [ ] Internal review: confirm service dates, plugin versions, and bottleneck hints are accurate.

---

## Post-release

- [ ] Tag release in Git (`git tag -a vX.Y.Z`).
- [ ] Push tag to origin (`git push origin vX.Y.Z`).
- [ ] Draft **GitHub Release**:
  - Title = version + summary
  - Copy notes from `CHANGELOG.md` (formatting preserved)
  - Mention any **Known Issues**.
- [ ] **Verify GitHub Releases page is published with matching notes**
      (ensure formatting, Known Issues, and roadmap links are correct).
- [ ] Notify internal team (Slack/Email).
- [ ] Archive exported samples for record-keeping.
