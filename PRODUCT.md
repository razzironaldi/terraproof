# TerraProof — Product Context (`PRODUCT.md`)

## What It Is
**TerraProof** is an offline-first geodesic cadastral auditing, protected-area intersection verification, and biological yield mass-balance compliance workbench built for Indonesian smallholder agro-forestry supply chains exporting to the European Union under **EU Regulation 2023/1115 (EUDR)**.

## Who It Is For
- **Cooperative Compliance Officers & Cadastral Auditors:** Operating in West Lampung (Sumberjaya, Ulubelu, Sekincau) adjacent to the **Bukit Barisan Selatan National Park (TNBBS)** UNESCO World Heritage boundary (`2020-12-31` deforestation cutoff).
- **European Union Commodity Importers & Competent Authorities:** Reviewing deterministic TRACES NT Due Diligence Statements (DDS) and cryptographic SHA-256 + Merkle-root batch passports before customs release at Rotterdam, Hamburg, and Antwerp.

## Core Operational Workflows
1. **Cadastral Registry & Satellite Inspection (`01. Cadastral Registry & Map`):**
   - Inspect 15 geo-referenced smallholder farm polygons overlaid on Esri World Imagery and CartoDB Dark Matter base layers.
   - Verify spatial separation from the TNBBS strict conservation perimeter (`TNBBS-ZONE-CORE-01`) and detect illegal encroachment (`#7` H. Burhanuddin (`ULUBELU-7741`), `34.12%` overlap, `0.6310 ha` inside TNBBS).
   - Interactively draw and run instant server-side Shoelace geodesic + Sutherland-Hodgman intersection checks on custom polygon vertices.
2. **GPS Multipath Sanitization (`02. GPS Track Filter`):**
   - Inspect raw smartphone GPS vertices collected under dense tropical canopy alongside the sanitized perimeter polygon produced by the modified Ramer-Douglas-Peucker + spike-angle trajectory filter.
   - Compare raw vs. clean vertex coordinates, displacement metrics, and geodesic hectare recalculation for any selected parcel.
3. **Biological Yield Mass-Balance Ledger (`03. Yield Mass-Balance`):**
   - Audit cumulative harvested weight against biological carrying capacity (`Area × Tree Density × Age Factor × Agronomic Yield Max`).
   - Run interactive single-delivery mass-balance stress simulations to block illicit timber/crop laundering from neighbouring protected forest land (`#11` Dedi Kurniawan (`TNBBS-BUF-019`), `3,890 kg` actual vs `1,540 kg` max ceiling = `252.6%` utilization).
4. **Offline Field Queue & TRACES NT Export (`04. Offline Sync & DDS`):**
   - Queue field surveys into local `IndexedDB` storage during zero-connectivity canopy patrols and synchronize payloads once network connectivity returns.
   - Generate and print deterministic EUDR Due Diligence Passports (`/dds/{batch_code}/passport`) anchored by a verifiable SHA-256 hash chain and batch Merkle root.

## Domain Tone & Operational Posture
- **Tone:** Authoritative, deterministic, forensic, surveyor-grade.
- **Posture:** Dense atmospheric minimalism and high-craft dark UI (`impeccable` P4 paradigm) built for daylight/nighttime operational clarity, zero decorative noise, and 100% tabular numeric precision.
