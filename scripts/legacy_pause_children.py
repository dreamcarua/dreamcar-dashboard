#!/usr/bin/env python3
"""TEMP SHIM (X6M launch 09.07.2026): reuse Legacy Hygiene workflow to run
scripts/distribute_x6m_ads.py (distribute X6M creatives + launch DC|02-05).
Honors DRY_RUN. Original restored right after."""
import distribute_x6m_ads

if __name__ == '__main__':
    distribute_x6m_ads.main()
