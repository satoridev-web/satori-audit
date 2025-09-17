# ROADMAP

## 1) Short-term (Next)
- Toggle for weekly label style: “Wk of 05 Aug” vs “Wk1, Wk2…”.
- Section switches for PDF (include/exclude tables) + custom table columns.
- Vulnerability scan integration via filters (`satori_audit_v3_vuln_*`).
- REST API + WP-CLI: generate/send reports on demand; list bottlenecks; purge history.
- Improved LSCWP config diff + context-aware recommendations.

## 2) Medium-term
- Uptime & latency micro-checks with historical charts.
- WooCommerce summary: gateways, order throughput (non-PII).
- Object-cache benchmarking and Redis status summary.
- Signed PDFs or footer hash for tamper-evidence.
- Per-site overrides (multisite) and network dashboard.

## 3) Long-term
- Role/capability matrix beyond `manage_options`.
- Compliance mode: redact PII from exports.
- Multi-tenant packaging for commercialization (licensing, updates channel).
