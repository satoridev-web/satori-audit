# CHANGELOG

## [3.7.4.9] - 2025-09-18

### Fixed

- **Audit JSON export now refreshes live** when using _Run Audit Now_ (previously stale).
- Added **timestamp persistence** (`satori_audit_v3_json_last_generated`) using WordPress timezone (`wp_date()`).
- Added **admin notices** after manual run:
  - Blue info: _“Last generated at …”_
  - Green success: _“JSON export refreshed successfully.”_
- Injected **Audit Log entry** (_Audit JSON refreshed_) for traceability.

### Changed

- Updated `satori-audit.php` bootstrap to conditionally load `inc/z-satori-audit-json-refresh.php`.

### Known Issues

- The `plugin_version` inside the Audit JSON still reports `3.7.3`.
  This will be aligned in the upcoming **3.7.5** release when we fold the patch into core.

## [3.7.4] - 2025-09-02 (planned)

### Added

- Weekly label style toggle (calendar vs ordinal).
- Optional PDF “ordinal week” labels (Wk1–Wk5).

### Changed

- Table width manager: tighter cell padding on PDF.

### Fixed

- Edge case where a single-week change showed both date and week label.

All notable changes to **Satori Audit (mu-plugin)** will be documented in this file.

## [3.7.3] - 2025-09-02

### Added

- PDF layout controls (A4/Letter/Legal) and orientation (Portrait/Landscape).
- HTML Preview (browser) to verify layout before exporting PDF.
- Weekly lines logic: if only one weekly change in ~5 weeks, show a single date.
- Version prefix rendering (“v1.2.3”) across PDF/CSV/Markdown.
- Improved DOMPDF upload UX (clear notices, explicit submit, diagnostics).

### Changed

- Plugin table: fixed layout & wrapping to avoid truncation; landscape recommended for wide tables.
- Access control: settings/dashboard visibility can be limited to main/selected admins.

### Fixed

- Safelist persistence (prevent accidental clearing).
- Minor UI wording and admin-page descriptions.

## [3.7.2] - 2025-08-30

### Added

- Recipient safelist domains and addresses (internal-only comms).
- Backfill option to seed last 4 Mondays with current versions.
- AU-format footer timestamp.

## [3.7.1] - 2025-08-25

### Added

- DOMPDF installer (packaged ZIP) + probe.
- Initial weekly version lines and plugin diffs (NEW/UPDATED/DELETED).

## [3.7.0] - 2025-08-22

- Initial structured report refactor and exports.
