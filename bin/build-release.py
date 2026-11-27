#!/usr/bin/env python3
"""Build a reproducible plugin ZIP from an explicit runtime allowlist."""
import argparse
import hashlib
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
FILES = ['legal-entity-marks.php', 'uninstall.php', 'readme.txt',
         'docs/observer-service.md', 'LICENSE']
DIRECTORIES = ['includes', 'admin', 'public', 'data', 'languages']
SLUG = 'chekazoid-legal-entity-marks'
SUFFIXES = {'.php', '.css', '.js', '.json', '.pot', '.po', '.mo'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path)
    parser.add_argument('--github', action='store_true',
                        help='Keep the plugin directory used by existing GitHub installations')
    parser.add_argument('--assets', action='store_true', help='Also package the WordPress.org page images')
    args = parser.parse_args()
    version = re.search(r"define\('CLEM_VERSION', '([^']+)'\)",
                        (ROOT / 'legal-entity-marks.php').read_text())[1]
    plugin_dir = 'legal-entity-marks' if args.github else SLUG
    filename = f'{plugin_dir}-{version}.zip' if args.github else f'{SLUG}-{version}-wordpress.zip'
    output = args.output or ROOT.parent / filename
    paths = [ROOT / name for name in FILES]
    for directory in DIRECTORIES:
        paths.extend(p for p in (ROOT / directory).rglob('*')
                     if p.is_file() and p.suffix in SUFFIXES
                     and not p.name.endswith('-fetched.json'))
    for path in paths:
        if path.is_symlink():
            raise ValueError(f'Symlinks cannot be packaged: {path.name}')
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted(set(paths)):
            name = plugin_dir + '/' + path.relative_to(ROOT).as_posix()
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes())
    print(f'{output}\n{len(set(paths))} files\nSHA256 {hashlib.sha256(output.read_bytes()).hexdigest()}')
    if args.assets:
        assets = ROOT.parent / f'{SLUG}-{version}-wordpress-assets.zip'
        names = ['icon-128x128.png', 'icon-256x256.png', 'banner-772x250.png',
                 'banner-1544x500.png'] + [f'screenshot-{n}.png' for n in range(1, 5)]
        with zipfile.ZipFile(assets, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for name in sorted(names):
                path = ROOT / 'wordpress-assets' / name
                info = zipfile.ZipInfo('assets/' + name, date_time=(2026, 1, 1, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                archive.writestr(info, path.read_bytes())
        print(f'{assets}\n{len(names)} images\nSHA256 {hashlib.sha256(assets.read_bytes()).hexdigest()}')



if __name__ == '__main__':
    main()
