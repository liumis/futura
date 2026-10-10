# Admin navigation — Option 1 (shrink sidebar)

**Goal:** Keep full access via `AdminModule` / roles, but show only **primary** links in the sidebar. Everything else lives on **group hub pages** (tile menus). Rare setup stays under **System** or **Setup** sub-hubs.

**Rough size (staff user, all modules):**

| | Today (approx.) | After Option 1 |
|---|-----------------|----------------|
| Visible sidebar links | ~110–120 | ~38–42 |
| Top-level groups | 11 | 11 (most **collapsed by default**) |
| “+” quick actions | 4 | 4 (unchanged) |

---

## Global sidebar (target)

Order of groups (unchanged from `AdminPanelProvider`; collapse all except **Tasks** + **Finances** by default for staff — tune per role later in Option 2).

```
┌─ (top) ─────────────────────┐
│  Dashboard                  │  ← move out of “Reports” group (standalone, sort -1)
└─────────────────────────────┘

▸ Orders                      collapsed
▸ Warehouse & shipping      collapsed
▸ Reports                     collapsed
▸ Catalog                     collapsed
▸ Contacts                    collapsed
▸ Finances                    expanded (example default for accountant)
▸ Documents                   collapsed
▸ Tasks                       expanded (example default for PM)
▸ Employees & contracts       collapsed
▸ System                      collapsed
  (Financial options)         ← removed as top-level group; reachable from Finances hub
```

Quick actions at bottom of relevant groups (keep as today):

- Orders → **New order**
- Finances → **New invoice**
- Documents → **New document**
- Employees → **Payments** (Monthly payment)

---

## Per-group: sidebar vs hub

Legend:

- **Sidebar** — stays in Filament nav (`shouldRegisterNavigation = true`, low `navigationSort`)
- **Hub** — hidden from nav; linked from hub page only (`shouldRegisterNavigation = false`)
- **System** — only under System hub / admin setup

### Dashboard (new top-level)

| Sidebar | Hub | Notes |
|---------|-----|--------|
| Dashboard | — | Move from `Reports` group; home URL unchanged |

---

### Orders

**Hub page (new):** `Orders hub` — optional; group is small.

| Sidebar | Hub |
|---------|-----|
| Orders list | — |
| — | New order (already nav item) |

*No change needed if group stays small.*

---

### Warehouse & shipping

**Hub page (new):** `Warehouse hub`

| Sidebar | Hub / System |
|---------|----------------|
| Warehouse order | — |
| Import to stock | — |
| Stock | — |
| Reservations | — |
| **More warehouse…** → hub | Manual import, Shipping settings, Suppliers, Destinations, Packages, Warehouses settings, Countries, Mail templates, Write-off options, Product types (if used here) |

| Hub only (hide from sidebar) | Today’s label |
|--------------------------------|---------------|
| Manual import | Manual import |
| Shipping settings | Shipping settings |
| Suppliers | Suppliers |
| Destinations | Destinations |
| Packages | Packages |
| Warehouses settings | Warehouses settings |
| Countries | Countries |
| Mail templates | Mail templates |
| Write-off options | Write-off options (page) |

Already hidden: Write-off documents resource.

---

### Reports

**Hub page (new):** `Reports hub` — sections: **Operations**, **Charts**, **Tax & declarations**.

| Sidebar | Hub |
|---------|-----|
| Orders list | — |
| Warehouse orders | — |
| Sales | — |
| Low stock | — |
| **More reports…** → hub | Customer sales matrix, Late payments, Packing, Bank payment report, Orders chart, Stock chart, FR0564 export, FR0600, GPM312, GPM313 |

| Hub only | Notes |
|----------|--------|
| Customer sales matrix | |
| Late payments | |
| Packing | |
| Bank payment report | |
| Orders chart | **Remove duplicate:** hide `OrderCharts` (keep `ReportsOrdersChart` or vice versa — one chart page only) |
| Stock chart | |
| FR0564 / FR0600 / GPM312 / GPM313 | Tile group “Tax & declarations” |

| Remove / merge | Reason |
|----------------|--------|
| `OrderCharts` vs `ReportsOrdersChart` | Same purpose, two nav entries |
| `Charts` under Tasks | If it duplicates report charts, hub-only under Reports or Tasks |

---

### Catalog

**Hub page (new):** `Catalog hub`

| Sidebar | Hub |
|---------|-----|
| Products | — |
| Collections | — |
| Colors | — |
| Pricing | — |
| **More catalog…** → hub | Customer group prices, (other catalog CRUD) |

---

### Contacts

**Hub page:** existing **Contacts board** (`ContactsBoard` — label “Dashboard”)

| Sidebar | Hub |
|---------|-----|
| Dashboard | Contacts board (rename nav label to **Contacts board** for clarity) |
| Directory contacts | — |
| Directory stages | Hub |

---

### Finances

**Hub page (new):** `Finances hub` — sections: **Daily**, **Accounting reports**, **Setup**.

| Sidebar | Hub |
|---------|-----|
| Invoices | — |
| Bank statements | — |
| Payments | PaymentResource |
| Contacts | Counterparties (Finances group) |
| **More finances…** → hub | Balance, Profit (loss), PVM, Turnover sheet, Debit/credit, ISAF, Import tax payments, Invoice codes |

| Hub only | Notes |
|----------|--------|
| Balance | |
| Profit (loss) statement | |
| PVM | |
| Turnover sheet | |
| Debit/credit | |
| ISAF | |
| Import tax payments | |
| Invoice codes | Setup tile → or link to Financial options |

**Financial options** (entire group removed from sidebar):

| On Finances hub → “Financial setup” | Current group |
|-------------------------------------|---------------|
| PVM/VAT, Import taxes, Expense/income types, Invoice series, Business trip allowances, Customs contacts, Write-off options | Financial options |

---

### Documents

**Hub page (new):** `Documents hub`

| Sidebar | Hub |
|---------|-----|
| Documents | — |
| Incomplete documents | — |
| **More documents…** → hub | Verify document, Confirmations, Document types |

---

### Tasks

**Hub page (optional):** `Tasks hub` — group is medium-sized.

| Sidebar | Hub |
|---------|-----|
| Calendar | — |
| Kanban | — |
| Tasks | TaskResource |
| Projects board | — |
| **More tasks…** → hub | Notes, Timeline, Charts, My comments, Project expenses, Projects, Project stages/types |

---

### Employees & contracts

**Hub page (new):** `Employees hub` — sections: **People**, **Time off & travel**, **Payroll**, **Setup**.

| Sidebar | Hub |
|---------|-----|
| Work calendar | AbsenceCalendar |
| Leave requests | — |
| Business trip requests | — |
| Employees | — |
| **More HR…** → hub | Contracts, Dividends, Work schedule, Overtime requests, Shareholders, Leave/OT types, Activity (if any) |

| Hub only | Notes |
|----------|--------|
| Employees contracts | |
| Dividend payments | |
| Work schedule | |
| Overtime requests | |
| Shareholders | |
| Leave request types | Setup |
| Overtime request types | Setup |

Nav item **Payments** → Monthly payment (keep; not in resource list).

Already hidden: Monthly payment page duplicate, Employee one-time payments, Payment reports page.

---

### System

**Hub page (new):** `System hub` — admin-only flavour.

| Sidebar | Hub |
|---------|-----|
| Users | — |
| My company | — |
| **System settings…** → hub | SharePoint, Dokobit, Outlook calendar, Other settings, Currencies, LT holidays, Countries (origin), Notification logs, Email logs, Activity logs |

---

## Hub page UX (wireframe)

Same pattern as Dashboard tiles / Contacts board:

```
┌─────────────────────────────────────────────┐
│  Finances                                   │
├─────────────────────────────────────────────┤
│  Daily                                      │
│  [ Invoices ]  [ Bank statements ]          │
│  [ Payments ]  [ Counterparties ]           │
├─────────────────────────────────────────────┤
│  Accounting reports                         │
│  [ Balance ] [ P&L ] [ PVM ] [ Turnover ]   │
│  [ Debit/credit ] [ ISAF ]                  │
├─────────────────────────────────────────────┤
│  Setup                                      │
│  [ Financial setup → ]  [ Invoice codes ]   │
└─────────────────────────────────────────────┘
```

Each tile: title, one-line description, badge if incomplete items exist (e.g. Incomplete documents count on Documents hub).

---

## Implementation checklist (when you build Option 1)

1. Add hub Filament pages: `FinancesHub`, `ReportsHub`, `WarehouseHub`, `EmployeesHub`, `DocumentsHub`, `SystemHub` (+ Catalog/Tasks if needed).
2. Add trait or `shouldRegisterNavigation(): bool` check against manifest class e.g. `AdminNavigation::sidebarVisible($class)`.
3. Set `shouldRegisterNavigation = false` on all **Hub only** rows above (batch).
4. Register one **“More …”** `NavigationItem` per group pointing to hub URL (`sort = 50`).
5. Move **Dashboard** to navigation group `null` or dedicated top item; set `homeUrl` unchanged.
6. Merge duplicate chart pages (pick one Orders chart implementation).
7. Filament: `->collapsibleNavigationGroups(true)` + default collapsed (if supported) or CSS/JS for initial collapse.
8. QA: project manager with 2 modules still sees only their groups; staff sees hubs for all permitted modules.

---

## What Option 1 deliberately does *not* do

- No per-user menu order (that’s Option 3).
- No role-specific default expanded groups (Option 2 — easy follow-up using same manifest).
- No command palette (optional add-on).

---

## Suggested pilot

Implement **Finances hub + Reports hub** first (largest win), then **Employees** and **Warehouse**. Orders/Contacts can stay as-is.
