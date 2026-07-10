#!/usr/bin/env python3
"""TEMP SHIM (10.07.2026): reuse Legacy Hygiene workflow to run scripts/fix_broken_x6m.py
(archive broken WITH_ISSUES X6M картинки in DC|02-05). Honors DRY_RUN. Original restored after."""
import fix_broken_x6m

if __name__ == '__main__':
    fix_broken_x6m.main()
