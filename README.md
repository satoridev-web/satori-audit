# Project: Satori Audit

This folder collects the working docs and samples for the Satori Audit mu-plugin project.

## Structure
- `/docs/`
  - **Satori-Audit-Plugin-Tech-Brief-v1.md** (source of truth)
  - Satori-Audit-Plugin-Tech-Brief-v1.html / .pdf (view/export variants)
  - CHANGELOG.md (semantic changes)
  - ROADMAP.md (prioritized tasks)
- `/plugin/`
  - (Place the current `satori-audit.php` here for versioning; production copy lives in `wp-content/mu-plugins/`)
- `/samples/`
  - sample-report.html (export-like HTML)
  - sample-plugins.csv (toy dataset)

## Workflow
1. Update the plugin in your dev site.
2. Export **HTML Preview** from the plugin UI and save into `/samples/` as a new snapshot.
3. When you bump versions, add entries to **CHANGELOG.md**.
4. Keep **ROADMAP.md** in sync with priorities.

**Note:** The WordPress-side PDF export (DOMPDF) is the canonical way to create client PDFs. The HTML file here is useful for quick reviews and printing to PDF if needed.
