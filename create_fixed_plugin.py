#!/usr/bin/env python3
"""Build a deterministic installable WP Deep Diagnostics plugin archive.

The repository root is the only editable plugin source. This script assembles that
source into build/wp-deep-diagnostics and creates dist/wp-deep-diagnostics.zip.
"""

from __future__ import annotations

import argparse
import shutil
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PLUGIN_SLUG = "wp-deep-diagnostics"
SOURCE_FILES = ("wp-deep-diagnostics.php", "uninstall.php", "README.md")
SOURCE_DIRS = ("src", "templates", "assets", "languages")
ZIP_TIMESTAMP = (2020, 1, 1, 0, 0, 0)


def assemble(output_root: Path) -> Path:
    plugin_dir = output_root / PLUGIN_SLUG
    if plugin_dir.exists():
        shutil.rmtree(plugin_dir)
    plugin_dir.mkdir(parents=True, exist_ok=True)

    for relative in SOURCE_FILES:
        source = ROOT / relative
        if not source.is_file():
            raise FileNotFoundError(f"Required plugin source is missing: {relative}")
        shutil.copyfile(source, plugin_dir / relative)

    for directory in SOURCE_DIRS:
        source_dir = ROOT / directory
        if not source_dir.is_dir():
            raise FileNotFoundError(f"Required plugin source directory is missing: {directory}")

        for source in sorted(path for path in source_dir.rglob("*") if path.is_file()):
            if source.name == ".gitkeep":
                continue
            relative = source.relative_to(ROOT)
            destination = plugin_dir / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, destination)

    return plugin_dir


def create_archive(plugin_dir: Path, dist_dir: Path) -> Path:
    dist_dir.mkdir(parents=True, exist_ok=True)
    archive = dist_dir / f"{PLUGIN_SLUG}.zip"
    if archive.exists():
        archive.unlink()

    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_STORED) as bundle:
        for source in sorted(path for path in plugin_dir.rglob("*") if path.is_file()):
            relative = source.relative_to(plugin_dir).as_posix()
            info = zipfile.ZipInfo(f"{PLUGIN_SLUG}/{relative}", ZIP_TIMESTAMP)
            info.compress_type = zipfile.ZIP_STORED
            info.external_attr = 0o100644 << 16
            bundle.writestr(info, source.read_bytes())

    return archive


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--build-dir", type=Path, default=ROOT / "build")
    parser.add_argument("--dist-dir", type=Path, default=ROOT / "dist")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    plugin_dir = assemble(args.build_dir.resolve())
    archive = create_archive(plugin_dir, args.dist_dir.resolve())
    print(f"Assembled: {plugin_dir}")
    print(f"Archive:   {archive}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
