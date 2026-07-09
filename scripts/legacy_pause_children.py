#!/usr/bin/env python3
"""TEMP SHIM (cleanup 09.07.2026): reuse Legacy Hygiene workflow to run
scripts/archive_old_ads.py (archive old ads in DC|01-05). Honors DRY_RUN.
Original restored right after."""
import archive_old_ads

if __name__ == '__main__':
    archive_old_ads.main()
