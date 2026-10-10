#!/usr/bin/env python3
"""Build an exact development qualification ZIP from clean committed source.

This does not create a release/tag or overwrite an existing artifact.
"""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile
import zipfile

SLUG = "cetech-woocommerce-delivery-engine"
ITEMS = (SLUG + ".php", "uninstall.php", "src", "database", "composer.json", "composer.lock", "languages", "assets", "readme.txt")

def run(*args, cwd=None):
    return subprocess.check_output(args, cwd=cwd, stderr=subprocess.STDOUT)

def digest(data):
    return hashlib.sha256(data).hexdigest()

def build(repo, ref, destination):
    if destination.suffix != ".zip" or destination.is_symlink() or destination.with_suffix(".json").is_symlink():
        raise ValueError("Qualification destination must be a new regular .zip path")
    repo = repo.resolve()
    ref = run("git", "rev-parse", ref + "^{commit}", cwd=repo).decode().strip()
    tree = run("git", "rev-parse", ref + "^{tree}", cwd=repo).decode().strip()
    if not re.fullmatch("[0-9a-f]{40}", ref) or not re.fullmatch("[0-9a-f]{40}", tree):
        raise ValueError("Unknown source identity")
    if run("git", "status", "--porcelain", cwd=repo):
        raise ValueError("Tracked source must be clean before package qualification")
    if destination.exists() or destination.with_suffix(".json").exists():
        raise ValueError("Qualification artifacts are immutable; choose a new destination")
    entries = run("git", "ls-tree", "-r", "-z", ref, "--", *ITEMS, cwd=repo).decode().split("\0")
    tracked = []
    for entry in filter(None, entries):
        meta, path = entry.split("\t", 1)
        mode, kind, blob = meta.split(" ")
        if mode not in ("100644", "100755") or kind != "blob" or ".." in Path(path).parts or Path(path).is_absolute():
            raise ValueError("Unpackable committed production mode/path")
        tracked.append(path)
    if not tracked or SLUG + ".php" not in tracked:
        raise ValueError("Incomplete committed production closure")
    expected = {path: digest(run("git", "show", ref + ":" + path, cwd=repo)) for path in tracked}
    with tempfile.TemporaryDirectory(prefix="cetech-p06-package-") as work:
        work = Path(work)
        stage = work / SLUG
        stage.mkdir()
        present_items = [item for item in ITEMS if any(path == item or path.startswith(item + "/") for path in tracked)]
        archive = run("git", "archive", "--format=tar", ref, "--", *present_items, cwd=repo)
        with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
            tar.extractall(stage, filter="data")
        run("composer", "install", "--no-dev", "--optimize-autoloader", "--no-interaction", "--prefer-dist", cwd=stage)
        installed = json.loads((stage / "vendor/composer/installed.json").read_text())
        if installed.get("dev") is not False or installed.get("dev-package-names"):
            raise ValueError("Development dependencies entered package")
        for path, sha in expected.items():
            if digest((stage / path).read_bytes()) != sha:
                raise ValueError("Build changed committed source: " + path)
        verifier = work / "verify-production-package-autoload.php"
        verifier.write_bytes(run("git", "show", ref + ":scripts/verify-production-package-autoload.php", cwd=repo))
        lint = work / "ci-lint-php.sh"
        lint.write_bytes(run("git", "show", ref + ":scripts/ci-lint-php.sh", cwd=repo))
        run("php", str(verifier), str(stage))
        run("bash", str(lint), str(stage))
        files = {}
        for path in sorted(stage.rglob("*")):
            if path.is_symlink():
                raise ValueError("Package symlinks are forbidden")
            if path.is_file():
                files[path.relative_to(stage).as_posix()] = digest(path.read_bytes())
        if any(path.startswith(("tests/", "scripts/", ".git/", "node_modules/")) for path in files):
            raise ValueError("Development-only files entered package")
        destination.parent.mkdir(parents=True, exist_ok=True)
        # Sorted paths/fixed timestamp make the ZIP reproducible on the same Composer output.
        with zipfile.ZipFile(destination, "x", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as output:
            for path in files:
                info = zipfile.ZipInfo(SLUG + "/" + path, date_time=(2026, 1, 1, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                output.writestr(info, (stage / path).read_bytes())
        extract = work / "extract"
        with zipfile.ZipFile(destination) as archive:
            archive.extractall(extract)
        extracted = extract / SLUG
        actual = {path.relative_to(extracted).as_posix(): digest(path.read_bytes()) for path in sorted(extracted.rglob("*")) if path.is_file()}
        if actual != files:
            raise ValueError("Extracted ZIP differs from verified package")
        run("php", str(verifier), str(extracted))
        run("bash", str(lint), str(extracted))
        php_sources = {path: sha for path, sha in expected.items() if path.endswith(".php") and (path.startswith(("src/", "database/")) or path in (SLUG + ".php", "uninstall.php"))}
        report = {"format": "cetech-promise-qualification-package-v1", "status": "PASS", "source_head": ref, "source_tree": tree, "zip_sha256": digest(destination.read_bytes()), "zip_bytes": destination.stat().st_size, "committed_files": expected, "package_files": files, "production_php_sources": php_sources, "production_php_sources_hash": digest(json.dumps(php_sources, separators=(",", ":"), ensure_ascii=False).encode()), "validation_tools": {"autoload_sha256": digest(verifier.read_bytes()), "lint_sha256": digest(lint.read_bytes()), "php": run("php", "-r", "echo PHP_VERSION;").decode(), "composer": run("composer", "--version").decode().strip()}, "checks": {"clean_committed_source": True, "no_dev_dependencies": True, "extracted_exact": True, "autoload_passed": True, "php_lint_passed": True}, "limits": ["Development qualification package only; no release/tag/deployment or target-stack acceptance."]}
        with destination.with_suffix(".json").open("x") as metadata:
            metadata.write(json.dumps(report, indent=2) + "\n")
        print(json.dumps({"status": "PASS", "source_head": ref, "zip_sha256": report["zip_sha256"], "production_php_count": len(php_sources)}))

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("destination", type=Path)
    parser.add_argument("--repo", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--ref", default="HEAD")
    args = parser.parse_args()
    build(args.repo, args.ref, args.destination.resolve())
