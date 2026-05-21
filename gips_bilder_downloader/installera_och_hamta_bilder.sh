#!/usr/bin/env bash
set -euo pipefail
sudo mkdir -p /opt/www/produkter/{R,LS,SLH,D,DK}
sudo chown -R "$USER":"$USER" /opt/www/produkter
python3 -m venv /opt/www/gips-image-venv
/opt/www/gips-image-venv/bin/pip install --upgrade pip requests beautifulsoup4
/opt/www/gips-image-venv/bin/python fetch_gips_images.py --root /opt/www/produkter --overwrite
find /opt/www/produkter -maxdepth 2 -type f | sort
