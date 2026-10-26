#!/usr/bin/env python3
"""Publish queued Memory Page bundles to independent bucket and HTML Git repositories."""

from __future__ import annotations

import argparse
import fcntl
from fractions import Fraction
import json
import math
import os
from pathlib import Path
import re
import shutil
import socketserver
import subprocess
import sys
import tempfile
from typing import Any


SLUG_PATTERN = re.compile(r"^(?:[A-Za-z0-9]{16}|[a-f0-9]{32})$")
TOKEN_PATTERN = re.compile(r"^[a-f0-9]{32}$")
BRANCH_PATTERN = re.compile(r"^[A-Za-z0-9._/-]{1,200}$")
BASE_PATH_PATTERN = re.compile(r"^[A-Za-z0-9_.-]+$")
BUCKET_PATTERN = re.compile(r"^bucket[1-9][0-9]*$")
BUCKET_GIT_PATTERN = re.compile(
    r"^git@github\.com:[A-Za-z0-9_.-]+/tikool-memory-pages-(bucket[1-9][0-9]*)\.git$"
)
MEDIA_PATH_PATTERN = re.compile(r"^(photos|videos|audio)/[A-Za-z0-9._-]+$")
MAX_FILE_SIZE = 95 * 1024 * 1024
PHOTO_MAX_SIZE = 2 * 1024 * 1024
VIDEO_UPLOAD_MAX_SIZE = 90 * 1024 * 1024
VIDEO_DIRECT_MAX_SIZE = 25 * 1024 * 1024
AUDIO_MAX_SIZE = 20 * 1024 * 1024
MEDIA_MAX_DURATION = 60.0
FFMPEG_BINARY = os.environ.get("TIKOOL_FFMPEG", "ffmpeg")
FFPROBE_BINARY = os.environ.get("TIKOOL_FFPROBE", "ffprobe")


class PublishError(RuntimeError):
    """Safe error returned to WordPress."""


def command(*args: str, cwd: Path | None = None, check: bool = True) -> subprocess.CompletedProcess[str]:
    process = subprocess.run(
        list(args),
        cwd=str(cwd) if cwd else None,
        check=False,
        capture_output=True,
        text=True,
        timeout=300,
    )
    if check and process.returncode != 0:
        detail = (process.stderr or process.stdout or "Command failed").strip().splitlines()[-1]
        raise PublishError(detail[:500])
    return process


def git(repo: Path, *args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return command("git", "-C", str(repo), *args, check=check)


def safe_child(root: Path, child: Path) -> bool:
    return os.path.commonpath([root.resolve(), child.resolve()]) == str(root.resolve())


def load_request(payload: Any, tasks_root: Path) -> tuple[dict[str, Any], Path, str, str, str, int, int]:
    if not isinstance(payload, dict):
        raise PublishError("Invalid publisher request.")

    try:
        task_id = int(payload.get("task_id", 0))
        page_id = int(payload.get("page_id", 0))
    except (TypeError, ValueError) as error:
        raise PublishError("Invalid task or page record.") from error
    slug = str(payload.get("slug", ""))
    token = str(payload.get("token", ""))
    bucket = str(payload.get("bucket", ""))
    bucket_git_url = str(payload.get("bucket_git_url", ""))
    public_path = str(payload.get("public_path", ""))
    branch = str(payload.get("branch", ""))

    git_match = BUCKET_GIT_PATTERN.fullmatch(bucket_git_url)
    if task_id < 1 or page_id < 1:
        raise PublishError("Invalid task or page record.")
    if not SLUG_PATTERN.fullmatch(slug) or not TOKEN_PATTERN.fullmatch(token):
        raise PublishError("Invalid Memory Page task path.")
    if not git_match or git_match.group(1) != bucket or not BUCKET_PATTERN.fullmatch(bucket):
        raise PublishError("Invalid bucket repository.")
    if not BASE_PATH_PATTERN.fullmatch(public_path) or public_path in {".", ".."}:
        raise PublishError("Invalid public HTML path.")
    if not BRANCH_PATTERN.fullmatch(branch) or ".." in branch or branch.startswith(("/", "-")):
        raise PublishError("Invalid Git branch.")

    bundle = (tasks_root / token).resolve()
    if not safe_child(tasks_root, bundle) or not bundle.is_dir():
        raise PublishError("Publishing-task bundle does not exist.")
    try:
        staged = json.loads((bundle / "payload.json").read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        raise PublishError("Publishing-task payload is invalid.") from error
    if not isinstance(staged, dict):
        raise PublishError("Publishing-task payload is invalid.")
    expected = {
        "pageId": page_id,
        "slug": slug,
        "bucket": bucket,
        "bucketGitUrl": bucket_git_url,
    }
    if any(staged.get(key) != value for key, value in expected.items()):
        raise PublishError("Publishing-task payload does not match its WordPress task.")
    template = bundle / "template.html"
    if not template.is_file() or "__TIKOOL_MEMORY_MANIFEST_JSON__" not in template.read_text(encoding="utf-8"):
        raise PublishError("Publishing-task template is invalid.")

    validate_media(staged.get("media"), bundle)
    return staged, bundle, public_path, branch, bucket_git_url, task_id, page_id


def validate_media(media: Any, bundle: Path) -> None:
    if not isinstance(media, dict):
        raise PublishError("Publishing-task media is invalid.")
    seen: set[str] = set()
    for kind in ("photos", "videos", "audio"):
        items = media.get(kind, [])
        if not isinstance(items, list):
            raise PublishError("Publishing-task media list is invalid.")
        for item in items:
            if not isinstance(item, dict):
                raise PublishError("Publishing-task media item is invalid.")
            relative = str(item.get("src", ""))
            if not MEDIA_PATH_PATTERN.fullmatch(relative) or not relative.startswith(f"{kind}/") or relative in seen:
                raise PublishError("Publishing-task media path is invalid or duplicated.")
            seen.add(relative)

    uploads = bundle / "uploads"
    if uploads.exists():
        for file in uploads.rglob("*"):
            if file.is_symlink():
                raise PublishError("Publishing-task files may not be symbolic links.")
            if file.is_file():
                relative = file.relative_to(uploads).as_posix()
                if relative not in seen:
                    raise PublishError("Publishing-task bundle contains an unreferenced upload.")
                kind = relative.split("/", 1)[0]
                maximum = {
                    "photos": PHOTO_MAX_SIZE,
                    "videos": VIDEO_UPLOAD_MAX_SIZE,
                    "audio": AUDIO_MAX_SIZE,
                }[kind]
                if file.stat().st_size > maximum:
                    labels = {"photos": "2 MB", "videos": "90 MB", "audio": "20 MB"}
                    raise PublishError(f"{file.name} exceeds the {labels[kind]} upload limit.")


def probe_media(file: Path) -> dict[str, Any]:
    result = command(
        FFPROBE_BINARY,
        "-v", "error",
        "-show_entries", "format=duration:stream=codec_type,width,height,duration,avg_frame_rate,r_frame_rate",
        "-of", "json",
        str(file),
    )
    try:
        data = json.loads(result.stdout)
    except json.JSONDecodeError as error:
        raise PublishError(f"{file.name} could not be inspected.") from error
    if not isinstance(data, dict):
        raise PublishError(f"{file.name} could not be inspected.")
    return data


def media_duration(probe: dict[str, Any], filename: str) -> float:
    values: list[float] = []
    candidates = [probe.get("format", {}).get("duration")]
    candidates.extend(stream.get("duration") for stream in probe.get("streams", []) if isinstance(stream, dict))
    for candidate in candidates:
        try:
            duration = float(candidate)
        except (TypeError, ValueError):
            continue
        if math.isfinite(duration) and duration > 0:
            values.append(duration)
    if not values:
        raise PublishError(f"{filename} has no readable duration.")
    return max(values)


def video_stream(probe: dict[str, Any], filename: str) -> dict[str, Any]:
    for stream in probe.get("streams", []):
        if isinstance(stream, dict) and stream.get("codec_type") == "video":
            return stream
    raise PublishError(f"{filename} has no readable video stream.")


def frame_rate(stream: dict[str, Any]) -> float:
    for key in ("avg_frame_rate", "r_frame_rate"):
        try:
            value = float(Fraction(str(stream.get(key, "0/1"))))
        except (ValueError, ZeroDivisionError):
            continue
        if math.isfinite(value) and value > 0:
            return min(value, 30.0)
    return 30.0


def transcode_video(source: Path, relative: str, bundle: Path, probe: dict[str, Any]) -> str:
    stream = video_stream(probe, source.name)
    try:
        width = int(stream.get("width", 0))
        height = int(stream.get("height", 0))
    except (TypeError, ValueError) as error:
        raise PublishError(f"{source.name} has invalid video dimensions.") from error
    if width < 1 or height < 1:
        raise PublishError(f"{source.name} has invalid video dimensions.")

    if height > 720:
        target_height = 720
        target_width = max(2, int(round((width * target_height / height) / 2) * 2))
        scale = f"scale={target_width}:{target_height}"
    else:
        scale = "scale=trunc(iw/2)*2:trunc(ih/2)*2"

    original = Path(relative)
    final_relative = (original.parent / f"{original.stem}.mp4").as_posix()
    final_file = bundle / "uploads" / final_relative
    temporary = final_file.with_name(final_file.name + ".processing.mp4")
    temporary.unlink(missing_ok=True)
    try:
        command(
            FFMPEG_BINARY,
            "-nostdin", "-hide_banner", "-loglevel", "error", "-y",
            "-i", str(source),
            "-t", "60",
            "-map", "0:v:0", "-map", "0:a:0?",
            "-vf", scale,
            "-r", f"{frame_rate(stream):.3f}",
            "-c:v", "libx264", "-preset", "medium",
            "-b:v", "2800k", "-maxrate", "3000k", "-bufsize", "6000k",
            "-pix_fmt", "yuv420p",
            "-c:a", "aac", "-b:a", "128k",
            "-map_metadata", "-1", "-movflags", "+faststart",
            str(temporary),
        )
        if not temporary.is_file() or temporary.stat().st_size < 1:
            raise PublishError(f"{source.name} could not be processed.")
        if temporary.stat().st_size > VIDEO_DIRECT_MAX_SIZE:
            raise PublishError(f"{source.name} is still larger than 25 MB after processing.")
        processed_probe = probe_media(temporary)
        if media_duration(processed_probe, source.name) > MEDIA_MAX_DURATION + 0.1:
            raise PublishError(f"{source.name} is still longer than 1 minute after processing.")
        os.replace(temporary, final_file)
        if source != final_file:
            source.unlink()
    finally:
        temporary.unlink(missing_ok=True)
    return final_relative


def process_staged_media(staged: dict[str, Any], bundle: Path) -> None:
    uploads = bundle / "uploads"
    for item in staged["media"].get("audio", []):
        source = uploads / str(item["src"])
        if not source.is_file():
            continue
        duration = media_duration(probe_media(source), source.name)
        if duration > MEDIA_MAX_DURATION + 0.05:
            raise PublishError(f"{source.name} exceeds the 1 minute audio limit.")

    for item in staged["media"].get("videos", []):
        relative = str(item["src"])
        source = uploads / relative
        if not source.is_file():
            continue
        probe = probe_media(source)
        duration = media_duration(probe, source.name)
        if source.stat().st_size > VIDEO_DIRECT_MAX_SIZE or duration > MEDIA_MAX_DURATION + 0.05:
            item["src"] = transcode_video(source, relative, bundle, probe)

    temporary = bundle / "payload.json.processing"
    temporary.write_text(json.dumps(staged, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    os.replace(temporary, bundle / "payload.json")
    validate_media(staged.get("media"), bundle)


def checkout_bucket(url: str, branch: str, slug: str, work_parent: Path) -> Path:
    checkout = Path(tempfile.mkdtemp(prefix="bucket-checkout-", dir=work_parent))
    try:
        git(checkout, "init", "--quiet")
        git(checkout, "remote", "add", "origin", url)
        git(checkout, "sparse-checkout", "init", "--cone")
        git(checkout, "sparse-checkout", "set", slug, f"public/{slug}")
        fetched = git(checkout, "fetch", "--depth=1", "--filter=blob:none", "origin", branch, check=False)
        if fetched.returncode == 0:
            git(checkout, "checkout", "--quiet", "-B", branch, "FETCH_HEAD")
        else:
            heads = command("git", "ls-remote", "--heads", url, branch, check=False)
            if heads.returncode != 0 or heads.stdout.strip():
                detail = (fetched.stderr or fetched.stdout or "Bucket fetch failed").strip().splitlines()[-1]
                raise PublishError(detail[:500])
            git(checkout, "checkout", "--quiet", "--orphan", branch)
        return checkout
    except Exception:
        shutil.rmtree(checkout, ignore_errors=True)
        raise


def build_manifest(staged: dict[str, Any]) -> dict[str, Any]:
    slug = str(staged["slug"])
    bucket = str(staged["bucket"])
    base = f"https://{bucket}.tikool.com/{slug}/"
    media: dict[str, list[dict[str, str]]] = {}
    for kind in ("photos", "videos", "audio"):
        media[kind] = []
        for item in staged["media"].get(kind, []):
            relative = str(item["src"])
            media[kind].append({"src": base + relative, "name": str(item.get("name", Path(relative).name))})
    return {
        "version": 2,
        "slug": slug,
        "bucket": bucket,
        "scene": str(staged.get("scene", "garden")),
        "title": str(staged.get("title", "")),
        "pet": staged.get("pet", {}),
        "story": str(staged.get("story", "")),
        "media": media,
        "timeline": staged.get("timeline", []),
        "updatedAt": str(staged.get("updatedAt", "")),
    }


def apply_bucket_bundle(checkout: Path, bundle: Path, staged: dict[str, Any], manifest: dict[str, Any]) -> None:
    slug = str(staged["slug"])
    legacy_page = checkout / slug
    page = checkout / "public" / slug
    if legacy_page.is_dir():
        page.parent.mkdir(parents=True, exist_ok=True)
        if page.exists():
            shutil.rmtree(legacy_page)
        else:
            shutil.move(str(legacy_page), str(page))
    page.mkdir(parents=True, exist_ok=True)
    desired = {
        str(item["src"])
        for kind in ("photos", "videos", "audio")
        for item in staged["media"].get(kind, [])
    }
    for kind in ("photos", "videos", "audio"):
        directory = page / kind
        directory.mkdir(parents=True, exist_ok=True)
        for existing in directory.iterdir():
            if existing.is_symlink() or existing.is_dir():
                raise PublishError("Bucket media directory contains an unsupported entry.")
            if f"{kind}/{existing.name}" not in desired:
                existing.unlink()

    for relative in desired:
        destination = page / relative
        upload = bundle / "uploads" / relative
        if upload.is_file():
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(upload, destination)
        elif not destination.is_file():
            raise PublishError(f"Required media file is missing: {relative}")

    manifest_json = json.dumps(manifest, ensure_ascii=False, indent=2, separators=(",", ": ")) + "\n"
    (page / "manifest.json").write_text(manifest_json, encoding="utf-8")
    for file in page.rglob("*"):
        if file.is_file() and file.stat().st_size > MAX_FILE_SIZE:
            raise PublishError(f"{file.name} exceeds the 95 MB publishing limit.")


def commit_bucket(checkout: Path, slug: str, branch: str, task_id: int) -> str:
    bucket_path = f"public/{slug}"
    paths = [bucket_path]
    if git(checkout, "ls-files", "--", slug).stdout.strip():
        paths.insert(0, slug)
    git(checkout, "add", "-A", "--", *paths)
    changed = git(checkout, "diff", "--cached", "--quiet", "--", *paths, check=False).returncode == 1
    if changed:
        git(
            checkout,
            "-c", "user.name=Tikool Memory Page",
            "-c", "user.email=memory-pages@tikool.com",
            "commit", "--quiet", "-m", f"Update Memory Page {slug} (task {task_id})", "--", *paths,
        )
    commit = git(checkout, "rev-parse", "HEAD").stdout.strip()
    if not re.fullmatch(r"[a-f0-9]{40}", commit):
        raise PublishError("Bucket Git repository did not return a valid commit.")
    git(checkout, "push", "--set-upstream", "origin", f"HEAD:{branch}")
    return commit


def javascript_json(value: dict[str, Any]) -> str:
    encoded = json.dumps(value, ensure_ascii=False, separators=(",", ":"))
    return (
        encoded.replace("&", "\\u0026")
        .replace("<", "\\u003c")
        .replace(">", "\\u003e")
        .replace("\u2028", "\\u2028")
        .replace("\u2029", "\\u2029")
    )


def update_html_repository(repo: Path, public_path: str, branch: str, slug: str, template: str, manifest: dict[str, Any], task_id: int) -> tuple[str, str]:
    remote_path = f"{public_path}/{slug}.html"
    target = repo / remote_path
    if not safe_child(repo, target):
        raise PublishError("Generated HTML path escapes its repository.")

    fetched = git(repo, "fetch", "origin", branch, check=False)
    if fetched.returncode == 0:
        git(repo, "merge", "--ff-only", "FETCH_HEAD")
    target.parent.mkdir(parents=True, exist_ok=True)
    html = template.replace("__TIKOOL_MEMORY_MANIFEST_JSON__", javascript_json(manifest))
    temporary = target.with_name(target.name + f".tmp-{os.getpid()}")
    temporary.write_text(html, encoding="utf-8")
    os.replace(temporary, target)

    git(repo, "add", "-A", "--", remote_path)
    changed = git(repo, "diff", "--cached", "--quiet", "--", remote_path, check=False).returncode == 1
    if changed:
        git(
            repo,
            "-c", "user.name=Tikool Memory Page",
            "-c", "user.email=memory-pages@tikool.com",
            "commit", "-m", f"Update Memory Page {slug} (task {task_id})", "--", remote_path,
        )
    commit = git(repo, "rev-parse", "HEAD").stdout.strip()
    if not re.fullmatch(r"[a-f0-9]{40}", commit):
        raise PublishError("HTML Git repository did not return a valid commit.")
    git(repo, "push", "--set-upstream", "origin", f"HEAD:{branch}")
    return commit, remote_path


def publish(html_repo: Path, tasks_root: Path, payload: Any) -> dict[str, Any]:
    staged, bundle, public_path, branch, bucket_git_url, task_id, _page_id = load_request(payload, tasks_root)
    slug = str(staged["slug"])
    bucket = str(staged["bucket"])
    process_staged_media(staged, bundle)
    manifest = build_manifest(staged)
    lock_path = html_repo / ".git" / "tikool-memory-page.lock"
    checkout: Path | None = None

    with lock_path.open("a+", encoding="utf-8") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        try:
            checkout = checkout_bucket(bucket_git_url, branch, slug, tasks_root)
            apply_bucket_bundle(checkout, bundle, staged, manifest)
            bucket_commit = commit_bucket(checkout, slug, branch, task_id)
            template = (bundle / "template.html").read_text(encoding="utf-8")
            html_commit, remote_path = update_html_repository(
                html_repo, public_path, branch, slug, template, manifest, task_id
            )
        finally:
            if checkout is not None:
                shutil.rmtree(checkout, ignore_errors=True)

    shutil.rmtree(bundle, ignore_errors=True)
    return {
        "ok": True,
        "html_commit": html_commit,
        "bucket_commit": bucket_commit,
        "remote_path": remote_path,
        "bucket_path": f"public/{slug}",
        "bucket": bucket,
        "media": staged["media"],
        "manifest": manifest,
    }


class PublisherHandler(socketserver.StreamRequestHandler):
    def handle(self) -> None:
        try:
            line = self.rfile.readline(65537)
            if not line or len(line) > 65536:
                raise PublishError("Publisher request is empty or too large.")
            payload = json.loads(line.decode("utf-8"))
            response = publish(self.server.repo, self.server.tasks_root, payload)  # type: ignore[attr-defined]
        except (PublishError, json.JSONDecodeError, OSError, ValueError, subprocess.TimeoutExpired) as error:
            response = {"ok": False, "error": str(error)[:500]}
        except Exception:
            print("Unexpected publisher error", file=sys.stderr)
            response = {"ok": False, "error": "The host Git publisher encountered an unexpected error."}
        self.wfile.write((json.dumps(response, separators=(",", ":")) + "\n").encode("utf-8"))


class PublisherServer(socketserver.UnixStreamServer):
    allow_reuse_address = True

    def __init__(self, socket_path: str, repo: Path, tasks_root: Path):
        self.repo = repo
        self.tasks_root = tasks_root
        super().__init__(socket_path, PublisherHandler)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo", required=True, type=Path, help="Persistent HTML Git checkout")
    parser.add_argument("--tasks", required=True, type=Path, help="Private staged task bundles")
    parser.add_argument("--socket", required=True, dest="socket_path", type=Path)
    parser.add_argument("--owner", type=int, default=0)
    parser.add_argument("--group", type=int, default=33)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()

    repo = args.repo.resolve()
    tasks_root = args.tasks.resolve()
    if not (repo / ".git").is_dir():
        print(f"Not a Git checkout: {repo}", file=sys.stderr)
        return 2
    for binary in (FFMPEG_BINARY, FFPROBE_BINARY):
        if shutil.which(binary) is None:
            print(f"Required media tool was not found: {binary}", file=sys.stderr)
            return 2
    tasks_root.mkdir(parents=True, exist_ok=True)
    if args.check:
        git(repo, "remote", "get-url", "origin")
        print(f"Publisher configuration is valid for {repo}; task root: {tasks_root}")
        return 0

    args.socket_path.parent.mkdir(parents=True, exist_ok=True)
    if args.socket_path.exists() or args.socket_path.is_socket():
        args.socket_path.unlink()
    server = PublisherServer(str(args.socket_path), repo, tasks_root)
    os.chmod(args.socket_path, 0o660)
    os.chown(args.socket_path, args.owner, args.group)
    try:
        server.serve_forever()
    finally:
        server.server_close()
        if args.socket_path.exists() or args.socket_path.is_socket():
            args.socket_path.unlink()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
