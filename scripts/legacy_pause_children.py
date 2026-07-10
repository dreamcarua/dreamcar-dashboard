#!/usr/bin/env python3
"""TEMP SHIM (10.07.2026): reuse Legacy Hygiene workflow to run scripts/duplicate_yadro.py
(copy working Ядро adset into DC|02-05 as PAUSED). Honors DRY_RUN. Original restored after."""
import duplicate_yadro

if __name__ == '__main__':
    duplicate_yadro.main()
