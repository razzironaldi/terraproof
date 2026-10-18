# TerraProof — Design System & Anti-Slop Specification (`DESIGN.md`)

## 1. Aesthetic Direction & Paradigm
- **Primary Paradigm:** **P4 — Atmospheric Minimalism & High-Craft Dark UI** (70%) blended with **P2 — Kinetic Typography & Neo-Brutalist Swiss Grids** (30%).
- **Domain Exemplars:** Palantir Foundry Geospatial, Mapbox Studio Cadastral Inspector, Linear Dark Workspace.
- **Core Principle:** Whisper-quiet luxury and surveyor-grade precision come from **1px hairline structural grids**, **strict typographic hierarchy**, and **tabular numeric alignment**—never from decorative glowing cards, giant hero banners, or generic SaaS metric blocks.

## 2. Intentional Dark Mode Palette (Carbon Slate & Emerald Canopy)
| Token / Role | Hex | Usage |
| :--- | :--- | :--- |
| `surface-canvas` | `#0B0F14` | Root viewport background (deep carbon slate, never pure `#000000`) |
| `surface-panel` | `#111720` | Primary workspace panes, table headers, and control bars |
| `surface-elevated` | `#161E2B` | Interactive hover states, subtle active row highlights, HUD overlays |
| `border-hairline` | `#1F2937` | 1px exposed structural grid hairlines separating panes and table rows |
| `border-Interactive` | `#30363D` | Form inputs, action buttons, and subtle badge frames |
| `text-primary` | `#F0F6FC` | Primary headings, active parcel identifiers, key numeric readouts |
| `text-secondary` | `#9CA3AF` | Body copy, agronomic metadata, table cells |
| `text-muted` | `#6E7681` | Coordinate units, cryptographic hash prefixes, column headers |
| `status-compliant` | `#10B981` | EUDR Article 3 Verified (`COMPLIANT`) polygons & status dots |
| `status-warning` | `#F59E0B` | Pending field verification (`PENDING_VERIFICATION`) |
| `status-danger` | `#EF4444` | Protected forest encroachment (`CRITICAL_BREACH`) & yield laundering (`YIELD_LAUNDERING_LOCKED`) |
| `accent-telemetry` | `#38BDF8` | Active selection focus rings, geodesic survey tools, and GPS filter path |

## 3. Typography & Scannable Hierarchy
- **UI & Operational Sans (`--font-sans`):** `'Plus Jakarta Sans'`, system-ui, sans-serif. Chosen for its high x-height, crisp geometric legibility in dense data tables, and authentic Indonesian engineering roots (avoiding overused generic defaults like `Inter`).
- **Telemetry & Cryptographic Mono (`--font-mono`):** `'JetBrains Mono'`, monospace with `font-variant-numeric: tabular-nums` enforced across all coordinates, hectare values, kg mass-balance metrics, and SHA-256 digests.
- **Type Scale:**
  - **Workspace Header Title:** `14px` (`0.875rem`), `font-weight: 600`, tracking `-0.01em`.
  - **Section / Pane Heading:** `13px` (`0.8125rem`), `font-weight: 600`, `#F0F6FC` (no uppercase eyebrow/kicker above headings).
  - **Table & Control Body:** `12px` (`0.75rem`), `font-weight: 500`, line-height `1.45`.
  - **Technical Metadata / Column Headers:** `11px` (`0.6875rem`), `font-weight: 600`, uppercase, tracking `0.05em`, `#6E7681`.

## 4. Layout Architecture & Viewport Budget
- **Desktop (`1440x900` & `1920x1080`):** Full-viewport (`100dvh`) split-pane workbench:
  - **Top Operational Bar (`44px`):** System identity, West Lampung cluster coordinates, inline summary strip (`15 Parcels`, `29.45 ha`, `12 Compliant`, `2 Breaches`, `Merkle Verified`), offline queue trigger, and DDS Passport links.
  - **Left Cadastral Viewport (`58%` width on desktop):** Interactive multi-layer Leaflet satellite/cadastral canvas with compact bottom-left geodesic legend and top-right vertex inspector.
  - **Right Forensic Workbench (`42%` width on desktop):** 4-tab operational console (`01. Cadastral Registry & Map`, `02. GPS Track Filter`, `03. Yield Mass-Balance`, `04. Offline Sync & DDS`) with maximum 3 distinct component groups visible without scrolling.
- **Mobile (`390px` width):** Responsive vertical flow with scrollable horizontal tab strip, full-width `320px`-tall interactive map pane, and horizontally scrollable tabular containers with preserved sticky context.

## 5. Strict Anti-Slop Bans (Enforced Across All Views)
1. **No Kickers / Eyebrows Above Headings:** Headings carry their own weight without redundant small uppercase labels stacked above them.
2. **No Colored `border-left` or `border-right` > 1px:** Callouts, alerts, and cards use uniform `1px solid` semantic borders and subtle background tints instead of thick left stripe callouts.
3. **No KPI Stat-Card Grids:** Summary metrics live inside a single compact horizontal telemetry strip in the top header bar.
4. **No Unrequested Marketing Chrome:** Zero hero banners, fake notification bells, user profile avatars, or decorative sparklines.
5. **Full Accessibility & Keyboard Navigation:** Visible `:focus-visible` outlines (`2px solid #38BDF8`), explicit `<label for="...">` associations on all form controls, and `aria-live="polite"` status feedback.
