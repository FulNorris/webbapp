#!/usr/bin/env python3
import argparse, csv, os, re, sys, time
from pathlib import Path
from urllib.parse import urljoin, urlparse
import requests
from bs4 import BeautifulSoup

CATEGORIES = [
    ("R", "https://gipsstuckaturer.se/utbud/takrosett-takrosetter/", "R"),
    ("LS", "https://gipsstuckaturer.se/utbud/lister/", "LS"),
    ("SLH", "https://gipsstuckaturer.se/utbud/hornlister/", "SLH"),
    ("D", "https://gipsstuckaturer.se/utbud/dorroverstycke/", "D"),
    ("DK", "https://gipsstuckaturer.se/utbud/dekorelement-och-konsoler/", "DK"),
]

SKU_PATTERNS = {
    "R": re.compile(r"\bR[P]?\d+[A-Z]?(?:-\d+)?\b", re.I),
    "LS": re.compile(r"\b(?:SL|LP|NLP)\d+[A-Z]?\b", re.I),
    "SLH": re.compile(r"\b(?:SLH|NLP|SL)\d+[A-Z]?\b", re.I),
    "D": re.compile(r"\bD\d+[A-Z]?\b", re.I),
    "DK": re.compile(r"\b(?:DK|ED|MC)\d+[A-Z]?\b", re.I),
}

UA = "Mozilla/5.0 (compatible; ProduktBildImporter/1.0)"
IMG_EXTS = (".jpg", ".jpeg", ".png", ".webp")

def safe(s: str) -> str:
    s = s.strip().upper().replace(" ", "")
    return re.sub(r"[^A-Z0-9_-]", "", s)

def get(session, url, timeout=30):
    r = session.get(url, timeout=timeout)
    r.raise_for_status()
    return r

def extract_product_links(html, base):
    soup = BeautifulSoup(html, "html.parser")
    links = []
    for a in soup.select('a[href]'):
        href = urljoin(base, a['href'])
        text = a.get_text(" ", strip=True)
        if "/butik/" in href and href not in links:
            links.append(href)
    return links

def sku_from_product(soup, folder, fallback_text=""):
    text = soup.get_text(" ", strip=True)
    m = re.search(r"Artikelnummer\s+([A-Za-z0-9_-]+)", text, re.I)
    if m:
        return safe(m.group(1))
    pat = SKU_PATTERNS.get(folder)
    if pat:
        m = pat.search(text + " " + fallback_text)
        if m:
            return safe(m.group(0))
    return None

def image_urls_from_product(soup, base):
    urls = []
    for img in soup.select("img"):
        candidates = []
        for attr in ["data-large_image", "data-src", "src"]:
            if img.get(attr):
                candidates.append(img.get(attr))
        if img.get("srcset"):
            # Use largest candidate from srcset
            parts = [p.strip().split(" ")[0] for p in img["srcset"].split(",") if p.strip()]
            candidates.extend(parts[::-1])
        for c in candidates:
            u = urljoin(base, c)
            low = u.lower()
            if any(ext in low for ext in IMG_EXTS) and "/wp-content/uploads/" in low:
                if "logo" in low or "loading" in low or "juliette" in low:
                    continue
                if u not in urls:
                    urls.append(u)
    return urls

def ext_from_url(url, content_type=""):
    path = urlparse(url).path.lower()
    for ext in IMG_EXTS:
        if ext in path:
            return ext.replace("jpeg", "jpg")
    if "png" in content_type: return ".png"
    if "webp" in content_type: return ".webp"
    return ".jpg"

def main():
    ap = argparse.ArgumentParser(description="Hämta produktbilder från gipsstuckaturer.se till /opt/www/produkter/*")
    ap.add_argument("--root", default="/opt/www/produkter")
    ap.add_argument("--delay", type=float, default=0.25)
    ap.add_argument("--overwrite", action="store_true")
    ap.add_argument("--manifest", default=None)
    args = ap.parse_args()

    root = Path(args.root)
    manifest = Path(args.manifest) if args.manifest else root / "produkter_manifest.csv"
    root.mkdir(parents=True, exist_ok=True)
    rows = []

    s = requests.Session()
    s.headers.update({"User-Agent": UA})

    for folder, url, _prefix in CATEGORIES:
        outdir = root / folder
        outdir.mkdir(parents=True, exist_ok=True)
        print(f"\n== {folder}: {url}")
        html = get(s, url).text
        links = extract_product_links(html, url)
        print(f"Produktlänkar: {len(links)}")
        for idx, product_url in enumerate(links, 1):
            try:
                pr = get(s, product_url)
                soup = BeautifulSoup(pr.text, "html.parser")
                title = soup.find("h1").get_text(" ", strip=True) if soup.find("h1") else product_url
                sku = sku_from_product(soup, folder, title)
                if not sku:
                    print(f"[SKIP] saknar artikelnummer: {product_url}")
                    continue
                imgs = image_urls_from_product(soup, product_url)
                if not imgs:
                    print(f"[SKIP] inga bilder: {sku} {product_url}")
                    continue
                for n, img_url in enumerate(imgs, 1):
                    rr = get(s, img_url, timeout=60)
                    ext = ext_from_url(img_url, rr.headers.get("content-type", ""))
                    suffix = "" if n == 1 else f"_{n}"
                    dest = outdir / f"{sku}{suffix}{ext}"
                    if dest.exists() and not args.overwrite:
                        print(f"[OK finns] {dest}")
                    else:
                        dest.write_bytes(rr.content)
                        print(f"[OK] {dest}")
                    rows.append({
                        "sku": sku,
                        "folder": folder,
                        "image_path": str(dest),
                        "source_page": product_url,
                        "source_image": img_url,
                        "title": title,
                    })
                time.sleep(args.delay)
            except Exception as e:
                print(f"[FEL] {product_url}: {e}", file=sys.stderr)

    with manifest.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=["sku","folder","image_path","source_page","source_image","title"])
        w.writeheader(); w.writerows(rows)
    print(f"\nKlart. Manifest: {manifest}")
    print(f"Antal nedladdade/registrerade bildrader: {len(rows)}")

if __name__ == "__main__":
    main()
