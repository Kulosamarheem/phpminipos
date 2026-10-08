# Graph Report - .  (2026-08-06)

## Corpus Check
- 10 files · ~17,869 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 206 nodes · 313 edges · 18 communities (13 shown, 5 thin omitted)
- Extraction: 91% EXTRACTED · 9% INFERRED · 0% AMBIGUOUS · INFERRED: 27 edges (avg confidence: 0.72)
- Token cost: 124,489 input · 0 output

## Community Hubs (Navigation)
- Backend Routes & Order Tables
- POS E2E Test Scenarios
- Session Cart Backend
- App Config & Auth
- Money, VAT & Product Validation
- POS Cart Frontend (pos.js)
- Concurrent Checkout Test & CSRF
- Page & Report Helpers
- E2E Package Config
- Product Management Frontend (products.js)
- POS Sale Flow Steps
- Project Documentation Set
- Order Transaction DB Steps
- E2E Global Setup
- E2E Global Teardown
- Playwright Config File
- POS Sale Flow (Flow.txt)
- Phased Development Roadmap

## God Nodes (most connected - your core abstractions)
1. `products table` - 10 edges
2. `orders table` - 9 edges
3. `cart_json_response()` - 8 edges
4. `order_items table` - 7 edges
5. `loadProducts()` - 6 edges
6. `product_validated_input()` - 6 edges
7. `refreshTotals()` - 6 edges
8. `money_order_totals()` - 6 edges
9. `users table` - 6 edges
10. `page_esc()` - 5 edges

## Surprising Connections (you probably didn't know these)
- `Save order to MySQL and deduct stock (Transaction)` --semantically_similar_to--> `Sale Transaction Flow Diagram (Database.txt)`  [INFERRED] [semantically similar]
  Flow.txt → Database.txt
- `tests/vat_breakdown.php — CLI VAT formula check` --references--> `money_order_totals()`  [EXTRACTED]
  tests/e2e/README.md → includes/money.php
- `VAT Calculation (inclusive/exclusive modes)` --rationale_for--> `money_order_totals()`  [EXTRACTED]
  SYSTEM_DESIGN.md → includes/money.php
- `app_fail()` --calls--> `render_error_page()`  [INFERRED]
  config/settings.php → includes/page.php
- `CSRF Protection on Mutation Endpoints` --rationale_for--> `csrf_token()`  [EXTRACTED]
  PROJECT_MAP.md → includes/auth.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Cart Management Flow** — pos, cart_add, cart_update, cart_remove, cart_get, cart_clear, cart_totals, includes_cart [EXTRACTED 1.00]
- **Shared VAT Calculation via money_order_totals** — checkout, cart_totals, includes_money_money_order_totals, system_design_vat_calculation [EXTRACTED 1.00]
- **Phased Development Roadmap (Phase 0-6)** — system_design_phase0_foundation, system_design_phase1_auth, system_design_phase2_product_cart, system_design_phase3_checkout, system_design_phase4_receipt, system_design_phase5_reports, system_design_phase6_hardening, system_design_phased_development [EXTRACTED 1.00]
- **POS Sale Flow Steps** — flow_stock_check, flow_cart_add_to_session, flow_calculate_totals, flow_checkout_payment, flow_payment_amount_check, flow_change_calculation, flow_save_to_mysql_transaction, flow_receipt_print [EXTRACTED 1.00]

## Communities (18 total, 5 thin omitted)

### Community 0 - "Backend Routes & Order Tables"
Cohesion: 0.11
Nodes (18): assets/css/print.css — 80mm receipt print styles, assets/css/style.css — login/main/POS/report styles, report_where(), order_items table, orders table, products table, stock_movements table, users table (+10 more)

### Community 1 - "POS E2E Test Scenarios"
Cohesion: 0.16
Nodes (18): baht(), createProduct(), deactivateProduct(), editingRow(), { expect }, login(), money, productRow() (+10 more)

### Community 2 - "Session Cart Backend"
Cohesion: 0.18
Nodes (14): cart_add_product(), cart_find_active_product_by_barcode(), cart_find_active_product_by_id(), cart_items(), cart_json_response(), cart_request_data(), cart_require_login(), cart_send_state() (+6 more)

### Community 3 - "App Config & Auth"
Cohesion: 0.15
Nodes (11): config/env.production.php.example — production env template, app_fail(), app_is_development(), is_logged_in(), is_valid_csrf_token(), require_login(), require_role(), sql/schema.sql — MySQL schema & admin seed (+3 more)

### Community 4 - "Money, VAT & Product Validation"
Cohesion: 0.12
Nodes (10): money_from_cents(), money_order_totals(), money_to_cents(), product_validated_input(), VAT Calculation (inclusive/exclusive modes), End-to-end Test (Playwright) README, Playwright end-to-end test suite, E2E scenario: net total incl. VAT on pos.php (+2 more)

### Community 5 - "POS Cart Frontend (pos.js)"
Cohesion: 0.29
Nodes (14): baht(), escapeHtml(), loadCart(), quantityControls(), refreshTotals(), renderCart(), renderChange(), renderTotals() (+6 more)

### Community 6 - "Concurrent Checkout Test & CSRF"
Cohesion: 0.21
Nodes (10): CurlHandle, csrf_token(), CSRF Protection on Mutation Endpoints, Phase 6 — Hardening, curl_handle(), extract_attribute(), http_get(), http_post_form() (+2 more)

### Community 7 - "Page & Report Helpers"
Cohesion: 0.21
Nodes (11): page_esc(), page_security_headers(), render_error_page(), PDO, report_cashiers(), report_parse_filters(), report_query_string(), report_render_filter_errors() (+3 more)

### Community 8 - "E2E Package Config"
Cohesion: 0.15
Nodes (12): @playwright/test, description, devDependencies, @playwright/test, name, private, scripts, demo (+4 more)

### Community 9 - "Product Management Frontend (products.js)"
Cohesion: 0.44
Nodes (10): editRow(), escapeAttr(), escapeHtml(), loadProducts(), removeRow(), renderProducts(), request(), saveRow() (+2 more)

### Community 10 - "POS Sale Flow Steps"
Cohesion: 0.22
Nodes (9): Sale Transaction Flow Diagram (Database.txt), Calculate total, discount, tax, Add product to session cart, Calculate change due, Checkout: select payment method, Verify received amount covers net total, Issue bill / print receipt and clear cart, Save order to MySQL and deduct stock (Transaction) (+1 more)

### Community 11 - "Project Documentation Set"
Cohesion: 0.47
Nodes (6): Database.txt (proposed transaction flow), Flow.txt (proposed POS sale flow), graphify-out/ — generated knowledge graph, Project Map — Mini POS & Billing System, Server-rendered PHP, No Framework/Router, Mini POS & Billing System — System Design

### Community 12 - "Order Transaction DB Steps"
Cohesion: 0.50
Nodes (4): Step 2: Loop INSERT into order_items, Step 1: INSERT into orders (bill header), Commit/Rollback error handling for sale transaction, Step 3: UPDATE products stock deduction

## Knowledge Gaps
- **38 isolated node(s):** `{ expect }`, `money`, `{ execFileSync }`, `path`, `{ execFileSync }` (+33 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **5 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `products table` connect `Backend Routes & Order Tables` to `Session Cart Backend`, `Money, VAT & Product Validation`?**
  _High betweenness centrality (0.074) - this node is a cross-community bridge._
- **Why does `money_order_totals()` connect `Money, VAT & Product Validation` to `Backend Routes & Order Tables`?**
  _High betweenness centrality (0.055) - this node is a cross-community bridge._
- **Why does `require_login()` connect `App Config & Auth` to `Backend Routes & Order Tables`?**
  _High betweenness centrality (0.039) - this node is a cross-community bridge._
- **What connects `{ expect }`, `money`, `{ execFileSync }` to the rest of the system?**
  _38 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Backend Routes & Order Tables` be split into smaller, more focused modules?**
  _Cohesion score 0.10574712643678161 - nodes in this community are weakly interconnected._
- **Should `App Config & Auth` be split into smaller, more focused modules?**
  _Cohesion score 0.14619883040935672 - nodes in this community are weakly interconnected._
- **Should `Money, VAT & Product Validation` be split into smaller, more focused modules?**
  _Cohesion score 0.11764705882352941 - nodes in this community are weakly interconnected._