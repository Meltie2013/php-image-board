# PHP Image Board - System Requirements & Storage

This document defines recommended hardware, software, and storage strategies to run the PHP Image Board efficiently.

---

## 1. Software

- **OS**: Linux/Unix (Ubuntu Server or CentOS/RHEL). **Avoid Windows Server**.  
- **PHP**: 8.x or newer.  
- **Database**: MariaDB / MySQL 8.x.  
- **Cache**: Redis or Memcached.  
- **Web Server**: Nginx preferred, Apache + PHP-FPM supported.

---

## 2. Hardware

- **CPU**: 40 cores (multi-threaded).  
- **Memory**: 256 GB RAM.  
- **Storage**:  
  - OS + App + DB: ~2 TB  
  - Images: 6 TB dedicated (can scale to 20 TB if admin has additional storage)  

---

## 3. Image Storage

Expected upload size: **0.5 MB – 2 MB**.  

- Smaller than 1 MB → higher capacity than table below.  
- Baseline assumes 0.5 MB, 1 MB, 1.5 MB, 2 MB exactly.

| Avg Size | Images/6TB | Images/20TB |
|----------|------------|------------|
| 0.5 MB   | 12,000,000 | 40,000,000 |
| 1 MB     | 6,000,000  | 20,000,000 |
| 1.5 MB   | 4,000,000  | 13,333,000 |
| 2 MB     | 3,000,000  | 10,000,000 |

**Recommendation**: Plan around **1.5 MB average** (~4M images @ 6 TB; ~13M images @ 20 TB).

---

## 4. Caching

- **App**: Redis/Memcached for sessions & metadata.  
- **Images**:  
  - <1 MB → disk cache  
  - 1–2 MB → memory cache  
- Optional: CDN for high-traffic offloading.

---

## 5. System Resources

- **CPU**: 40 cores for PHP workers & background tasks.  
- **Memory**: 64–128 GB for caching & in-memory image operations.  
- **DB**: ~1 TB on OS/application drives.

---

## 6. Growth Projection (6 TB baseline, 1.5 MB avg)

| Uploads/day | Images/year | Years until full |
|------------|------------|----------------|
| 5,000      | 1.8M       | 2.2            |
| 10,000     | 3.6M       | 1.1            |
| 25,000     | 9.1M       | 0.5            |
| 50,000     | 18.2M      | 0.25           |

> Note: Smaller images (~0.5 MB) → storage lasts ~3× longer.  
> For **20 TB**, multiply all capacities by ~3.33×.

---

## 7. Pruning & Archiving Strategies

To maintain performance and extend storage life:  

- **Automatic pruning**: Delete old or low-traffic images after a set period (e.g., 1–2 years).  
- **Archive old images**: Move older uploads to offline or cheaper storage (e.g., object storage, NAS, or cold HDDs).  
- **Database maintenance**: Periodically optimize and archive metadata tables (hashes, tags, votes).  
- **CDN offloading**: For high-traffic boards, offload static image delivery to reduce load on primary storage.  
- **Monitoring**: Track disk usage trends and implement alerts when thresholds are reached.

These practices allow both **6 TB and 20 TB setups** to maintain speed and reliability over the long term.

---

## 8. Summary

- **OS**: Linux/Unix only  
- **PHP**: 8.x+  
- **Storage**:  
  - 6 TB dedicated → ~4M images @ 1.5 MB average  
  - 20 TB dedicated → ~13M images @ 1.5 MB average  
- **DB**: ~1 TB on OS/application drives  
- **Caching**: Redis/Memcached + disk + memory  
- **Growth**: 10k uploads/day → ~1 year @ 1.5 MB avg; smaller images extend lifespan significantly  
- **Expansion & Archiving**: Implement pruning, archiving, and CDN offloading as needed to maintain performance
