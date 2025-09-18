# SATORI Audit — Roadmap

This roadmap outlines planned milestones, recently closed items, and upcoming priorities for the SATORI Audit plugin.

---

## ✅ Recently Delivered

### v3.7.4.9 (2025-09-18)
- **Audit JSON Refresh Fix**: JSON export now updates live when using *Run Audit Now*.
- Added **timestamp persistence** (`satori_audit_v3_json_last_generated`) on each manual run.
- Added **admin notices** to confirm refresh visually:
  - Blue info: *“Last generated at …”*
  - Green success: *“JSON export refreshed successfully.”*
- Injected **Audit Log entry** (*Audit JSON refreshed*) for traceability.
- Updated bootstrap to load new `inc/z-satori-audit-json-refresh.php` patch.
- Confirmed via WP-CLI that both timestamp and JSON content update immediately.

### v3.7.4.8 (2025-09-12)
- **Run Audit Now** and **Run Test Audit** buttons under *Tools → SATORI Audit* for manual and dry-run reporting.
- **Audit Scheduler** with daily, weekly, or monthly options.
- **Audit Log** (last 5 runs shown by default, expandable to full history, with 12-month retention).
- **Summary Preference** selector (choose which audit type is highlighted in the dashboard summary).
- **Timestamps** in logs now use `wp_date()` to respect WordPress timezone settings.
- Fixed issue where log timestamps previously displayed in UTC/server time.
- Known Issue (since closed in v3.7.4.9): Audit JSON export was not refreshing live.

---

## 🔜 Upcoming Milestones

### v3.7.5
- Fold JSON refresh logic into the core `Satori_Audit_V374` class (remove patch once stable).
- Align `plugin_version` inside JSON (currently still reports `3.7.3`).
- UX polish:
  - Status banners and streamlined notices.
  - Optional background runner for JSON refresh.
- Further PDF/CSV/Markdown export refinements.

### v3.8.0 (planning)
- Extended reporting configuration (section toggles, notes, selective exports).
- Enhanced Report Editor UX (inline edits, dropdown preview by month).
- Potential integration with external APIs for version diffs.

---

## 📌 Longer-Term Considerations
- Improved performance for large plugin/theme lists.
- Extended safelist/recipient management for multi-site environments.
- Addon architecture to separate core vs. advanced reporting features.
- Automated weekly audit snapshots with archive browsing.

---

## 📝 Notes
- All items are subject to reprioritisation based on client feedback and internal testing.
- Minor patch releases (3.7.4.x) may continue for fixes and incremental improvements.
