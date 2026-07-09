#!/usr/bin/env python3
"""
TEMPORARY SHIM (pre-launch cleanup 09.07.2026).
The 'Legacy Hygiene' workflow (workflow_dispatch, no cron) is reused here to run
scripts/archive_old_ads.py, which ARCHIVES old ads in the current sales structure
DC|01-05 before new-project creatives are added. Honors the workflow's DRY_RUN input.
Original legacy-pause-children logic is restored immediately after this run.
"""
import archive_old_ads

if __name__ == '__main__':
    archive_old_ads.main()
