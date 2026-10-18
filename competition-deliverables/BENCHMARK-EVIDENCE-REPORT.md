# EMPIRICAL BENCHMARK EVIDENCE REPORT — TERRAPROOF EUDR ENGINE
**Command Executed:** `php artisan terraproof:benchmark`  
**Generated Timestamp:** `2026-10-18T04:39:16+00:00`  
**Runtime Environment:** PHP `8.5.3` | Laravel `13.30.1`  
**Case Study Corridor:** West Lampung & Tanggamus — Bukit Barisan Selatan National Park (TNBBS) Corridor

---

## 1. EXECUTIVE SUMMARY OF EMPIRICAL METRICS

| Benchmark Suite | Sample Size ($N$) | Primary Accuracy / Error Metric | Execution Latency | Verdict |
| :--- | :---: | :--- | :---: | :---: |
| **Suite 1: Canopy Multipath GPS Spike Sanitizer** | `50 Tracks` (`199 Spikes`) | **30.67% $\rightarrow$ 0%** Area Distortion Error (**100%** Spike Recall) | `0.57 ms` / track | **PASS (100% RFC 7946 Valid)** |
| **Suite 2: Sutherland-Hodgman Spatial Conflict Clipper** | `50 Polygons` | **100%** Classification Accuracy (`50/50`) | `3.44 ms` mean (`4.94 ms` p95) | **PASS (Sub-5ms Real-Time)** |
| **Suite 3: Biological Yield-Ceiling ($Y_{\max}$) Gatekeeper** | `100 Weigh-Ins` (`70 Valid / 30 Fraud`) | **100% Precision / 100% Recall** (`FP = 0, FN = 0`) | `0.0058 ms` / block | **PASS (100% Chain Intact)** |

---

## 2. SUITE 1: CANOPY GPS MULTIPATH SPIKE SANITIZER (`GeodesicAuditService::sanitizeGpsTrack`)

### Experimental Methodology
Fifty ($N = 50$) synthetic smallholder perimeter walks were generated across West Lampung coordinates (`104.060°E..104.092°E`, `-5.038°S..-5.016°S`), each injected with 3 to 5 severe L1 carrier multipath reflections ($40\text{ m}$ to $52\text{ m}$ coordinate jumps under dense coffee shade canopy).

### Empirical Results
- **Total Multipath Spikes Injected:** `199`
- **Total Multipath Spikes Stripped:** `199` (**100% Recall**)
- **Mean Geodesic Area Distortion Before Sanitization:** **30.67%**
- **Mean Geodesic Area Distortion After Sanitization:** **0%**
- **RFC 7946 Closed Counter-Clockwise Ring Validity:** **100%**
- **Mean Processing Latency per Polygon Track:** **`0.57 ms`**

---

## 3. SUITE 2: SUTHERLAND-HODGMAN GEODESIC INTERSECTION ENGINE (`GeodesicAuditService::auditSpatialConflicts`)

### Experimental Methodology
Fifty ($N = 50$) farm polygons were audited against the **Bukit Barisan Selatan National Park (`TNBBS-SECTOR-LIWA`)** boundary, **Register 39 Protected Forest (`HL-REG-39`)**, and neighboring smallholder plots using local tangent plane projection and Sutherland-Hodgman polygon clipping:
- `25` Compliant Agro-Forestry Polygons (`0.0%` overlap)
- `15` National Park Encroachment Polygons (`>1.0%` forest overlap)
- `10` Cadastral Neighbor Overlap Polygons (`>2.5%` neighbor overlap)

### Empirical Results
- **Classification Accuracy:** **100%** (`50 / 50`)
- **Mean Clipping & Geodesic Intersection Latency:** **`3.44 ms`**
- **95th Percentile (p95) Latency:** **`4.94 ms`**

---

## 4. SUITE 3: BIOLOGICAL YIELD-CEILING ($Y_{\max}$) ANTI-LAUNDERING GATEKEEPER (`YieldEnvelopeService`)

### Experimental Methodology
One hundred ($N = 100$) harvest weigh-in transactions (`70` legitimate deliveries within biological quota and `30` over-quota polygon laundering attacks attempting to smuggle illicit encroachment coffee through a compliant plot) were evaluated and chained via SHA-256.

### Empirical Results
- **True Positives (Laundering Attacks Intercepted):** `30 / 30`
- **True Negatives (Legitimate Harvests Approved):** `70 / 70`
- **False Positives (Honest Farmers Falsely Blocked):** `0`
- **False Negatives (Laundering Attacks Missed):** `0`
- **Precision / Recall:** **100% / 100%**
- **Seeded Database SHA-256 Chain Verification:** **100% Intact** (`25` chained ledger blocks verified from Genesis `0000...0000`).