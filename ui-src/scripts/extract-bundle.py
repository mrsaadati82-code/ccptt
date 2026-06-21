#!/usr/bin/env python3
"""
Extract the single-file vite build (dist/index.html) into
plugin's assets/finance-ui/{app.js, app.css}.

Run from project root or from ui-src/:
    cd ui-src/ && python3 scripts/extract-bundle.py
"""
import re
import sys
import os

# Resolve paths relative to this script
HERE = os.path.dirname(os.path.abspath(__file__))
UI_SRC = os.path.dirname(HERE)  # ui-src/
PLUGIN_ROOT = os.path.dirname(UI_SRC)  # ccptt/

DIST_HTML = os.path.join(UI_SRC, 'dist', 'index.html')
OUT_CSS = os.path.join(PLUGIN_ROOT, 'assets', 'finance-ui', 'app.css')
OUT_JS = os.path.join(PLUGIN_ROOT, 'assets', 'finance-ui', 'app.js')

if not os.path.exists(DIST_HTML):
    print(f'❌ {DIST_HTML} not found. Run `npm run build` first.')
    sys.exit(1)

html = open(DIST_HTML, encoding='utf-8').read()
styles = re.findall(r'<style[^>]*>([\s\S]*?)</style>', html)
scripts = re.findall(r'<script[^>]*type="module"[^>]*>([\s\S]*?)</script>', html)

os.makedirs(os.path.dirname(OUT_CSS), exist_ok=True)
open(OUT_CSS, 'w', encoding='utf-8').write('\n'.join(styles))
open(OUT_JS, 'w', encoding='utf-8').write('\n'.join(scripts))

print(f'✅ CSS: {sum(len(s) for s in styles)} bytes → {OUT_CSS}')
print(f'✅ JS:  {sum(len(s) for s in scripts)} bytes → {OUT_JS}')
