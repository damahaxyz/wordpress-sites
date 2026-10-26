#!/usr/bin/env python3
"""End-to-end local verification for the dual-repository Memory Page publisher."""

from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import re
import subprocess
import tempfile
import textwrap


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("memory_page_publisher", ROOT / "scripts/memory-page-publisher.py")
assert SPEC and SPEC.loader
PUBLISHER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PUBLISHER)


def run(*args: str, cwd: Path | None = None) -> None:
    subprocess.run(args, cwd=cwd, check=True, capture_output=True, text=True)


with tempfile.TemporaryDirectory(prefix="memory-page-publisher-test-") as temporary:
    root = Path(temporary)
    html_origin = root / "html-origin.git"
    bucket_origin = root / "tikool-memory-pages-bucket1.git"
    html_repo = root / "html"
    tasks = root / "tasks"
    token = "a" * 32
    slug = "AbCdEf1234567890"

    tools = root / "tools"
    tools.mkdir()
    ffprobe = tools / "ffprobe"
    ffprobe.write_text(textwrap.dedent("""\
        #!/usr/bin/env python3
        import json
        import sys
        name = sys.argv[-1]
        if name.endswith(".mp3"):
            data = {"format": {"duration": "30.0"}, "streams": [{"codec_type": "audio", "duration": "30.0"}]}
        elif name.endswith(".processing.mp4"):
            data = {"format": {"duration": "60.0"}, "streams": [{"codec_type": "video", "width": 1280, "height": 720, "duration": "60.0", "avg_frame_rate": "30/1"}]}
        else:
            data = {"format": {"duration": "70.0"}, "streams": [{"codec_type": "video", "width": 1920, "height": 1080, "duration": "70.0", "avg_frame_rate": "60/1"}]}
        print(json.dumps(data))
    """), encoding="utf-8")
    ffprobe.chmod(0o755)
    ffmpeg = tools / "ffmpeg"
    ffmpeg.write_text(textwrap.dedent("""\
        #!/usr/bin/env python3
        from pathlib import Path
        import sys
        Path(sys.argv[-1]).write_bytes(b"processed mp4")
    """), encoding="utf-8")
    ffmpeg.chmod(0o755)
    PUBLISHER.FFPROBE_BINARY = str(ffprobe)
    PUBLISHER.FFMPEG_BINARY = str(ffmpeg)

    run("git", "init", "--bare", str(html_origin))
    run("git", "init", "--bare", str(bucket_origin))
    run("git", "init", "-b", "main", str(html_repo))
    run("git", "config", "user.name", "Test", cwd=html_repo)
    run("git", "config", "user.email", "test@example.com", cwd=html_repo)
    (html_repo / "public").mkdir()
    (html_repo / "public/404.html").write_text("not found\n", encoding="utf-8")
    run("git", "add", "public/404.html", cwd=html_repo)
    run("git", "commit", "-m", "Initial HTML repository", cwd=html_repo)
    run("git", "remote", "add", "origin", str(html_origin), cwd=html_repo)
    run("git", "push", "-u", "origin", "main", cwd=html_repo)

    bucket_seed = root / "bucket-seed"
    run("git", "init", "-b", "main", str(bucket_seed))
    run("git", "config", "user.name", "Test", cwd=bucket_seed)
    run("git", "config", "user.email", "test@example.com", cwd=bucket_seed)
    (bucket_seed / slug / "photos").mkdir(parents=True)
    (bucket_seed / slug / "photos/legacy.jpg").write_bytes(b"legacy")
    run("git", "add", ".", cwd=bucket_seed)
    run("git", "commit", "-m", "Legacy bucket layout", cwd=bucket_seed)
    run("git", "remote", "add", "origin", str(bucket_origin), cwd=bucket_seed)
    run("git", "push", "-u", "origin", "main", cwd=bucket_seed)

    bundle = tasks / token
    (bundle / "uploads/photos").mkdir(parents=True)
    (bundle / "uploads/photos/a.jpg").write_bytes(b"fake jpeg bytes")
    staged = {
        "version": 1,
        "pageId": 42,
        "slug": slug,
        "bucket": "bucket1",
        "bucketGitUrl": str(bucket_origin),
        "scene": "garden",
        "title": "A pet memory",
        "pet": {"name": "Mochi"},
        "story": "Always remembered.",
        "media": {"photos": [{"src": "photos/a.jpg", "name": "a.jpg"}], "videos": [], "audio": []},
        "timeline": [],
        "updatedAt": "2026-10-05T00:00:00Z",
    }
    (bundle / "payload.json").write_text(json.dumps(staged), encoding="utf-8")
    (bundle / "template.html").write_text(
        "<script>window.__TIKOOL_MEMORY_MANIFEST__=__TIKOOL_MEMORY_MANIFEST_JSON__;</script>",
        encoding="utf-8",
    )

    bucket_prefix = re.escape(str(bucket_origin).removesuffix("bucket1.git"))
    PUBLISHER.BUCKET_GIT_PATTERN = re.compile(rf"^{bucket_prefix}(bucket1)\.git$")
    response = PUBLISHER.publish(
        html_repo,
        tasks,
        {
            "task_id": 7,
            "page_id": 42,
            "slug": slug,
            "token": token,
            "bucket": "bucket1",
            "bucket_git_url": str(bucket_origin),
            "public_path": "public",
            "branch": "main",
        },
    )
    assert response["ok"] is True
    assert not bundle.exists()
    assert not any(tasks.iterdir())
    html = (html_repo / f"public/{slug}.html").read_text(encoding="utf-8")
    assert f"https://bucket1.tikool.com/{slug}/photos/a.jpg" in html

    inspection = root / "bucket-inspection"
    run("git", "clone", "--branch", "main", str(bucket_origin), str(inspection))
    manifest = json.loads((inspection / "public" / slug / "manifest.json").read_text(encoding="utf-8"))
    assert manifest["media"]["photos"][0]["src"] == f"https://bucket1.tikool.com/{slug}/photos/a.jpg"
    assert (inspection / "public" / slug / "photos/a.jpg").is_file()
    assert not (inspection / slug).exists()

    second_token = "b" * 32
    second_bundle = tasks / second_token
    (second_bundle / "uploads/audio").mkdir(parents=True)
    (second_bundle / "uploads/audio/voice.mp3").write_bytes(b"fake mp3 bytes")
    staged["media"] = {
        "photos": [],
        "videos": [],
        "audio": [{"src": "audio/voice.mp3", "name": "voice.mp3"}],
    }
    staged["updatedAt"] = "2026-10-05T01:00:00Z"
    (second_bundle / "payload.json").write_text(json.dumps(staged), encoding="utf-8")
    (second_bundle / "template.html").write_text(
        "<script>window.__TIKOOL_MEMORY_MANIFEST__=__TIKOOL_MEMORY_MANIFEST_JSON__;</script>",
        encoding="utf-8",
    )
    PUBLISHER.publish(
        html_repo,
        tasks,
        {
            "task_id": 8,
            "page_id": 42,
            "slug": slug,
            "token": second_token,
            "bucket": "bucket1",
            "bucket_git_url": str(bucket_origin),
            "public_path": "public",
            "branch": "main",
        },
    )
    run("git", "pull", "--ff-only", cwd=inspection)
    assert not (inspection / "public" / slug / "photos/a.jpg").exists()
    assert (inspection / "public" / slug / "audio/voice.mp3").is_file()
    assert not any(tasks.iterdir())

    third_token = "c" * 32
    third_bundle = tasks / third_token
    (third_bundle / "uploads/videos").mkdir(parents=True)
    large_video = third_bundle / "uploads/videos/large.mov"
    with large_video.open("wb") as handle:
        handle.truncate(26 * 1024 * 1024)
    staged["media"] = {
        "photos": [],
        "videos": [{"src": "videos/large.mov", "name": "large.mov"}],
        "audio": [],
    }
    staged["updatedAt"] = "2026-10-05T02:00:00Z"
    (third_bundle / "payload.json").write_text(json.dumps(staged), encoding="utf-8")
    (third_bundle / "template.html").write_text(
        "<script>window.__TIKOOL_MEMORY_MANIFEST__=__TIKOOL_MEMORY_MANIFEST_JSON__;</script>",
        encoding="utf-8",
    )
    response = PUBLISHER.publish(
        html_repo,
        tasks,
        {
            "task_id": 9,
            "page_id": 42,
            "slug": slug,
            "token": third_token,
            "bucket": "bucket1",
            "bucket_git_url": str(bucket_origin),
            "public_path": "public",
            "branch": "main",
        },
    )
    assert response["media"]["videos"][0]["src"] == "videos/large.mp4"
    run("git", "pull", "--ff-only", cwd=inspection)
    assert not (inspection / "public" / slug / "audio/voice.mp3").exists()
    assert not (inspection / "public" / slug / "videos/large.mov").exists()
    assert (inspection / "public" / slug / "videos/large.mp4").read_bytes() == b"processed mp4"
    manifest = json.loads((inspection / "public" / slug / "manifest.json").read_text(encoding="utf-8"))
    assert manifest["media"]["videos"][0]["src"] == f"https://bucket1.tikool.com/{slug}/videos/large.mp4"
    assert not any(tasks.iterdir())

    # A delete-only save has no new upload directory. It must still remove the
    # published file, update the manifest and complete normally.
    fourth_token = "d" * 32
    fourth_bundle = tasks / fourth_token
    fourth_bundle.mkdir(parents=True)
    staged["media"] = {"photos": [], "videos": [], "audio": []}
    staged["updatedAt"] = "2026-10-05T03:00:00Z"
    (fourth_bundle / "payload.json").write_text(json.dumps(staged), encoding="utf-8")
    (fourth_bundle / "template.html").write_text(
        "<script>window.__TIKOOL_MEMORY_MANIFEST__=__TIKOOL_MEMORY_MANIFEST_JSON__;</script>",
        encoding="utf-8",
    )
    response = PUBLISHER.publish(
        html_repo,
        tasks,
        {
            "task_id": 10,
            "page_id": 42,
            "slug": slug,
            "token": fourth_token,
            "bucket": "bucket1",
            "bucket_git_url": str(bucket_origin),
            "public_path": "public",
            "branch": "main",
        },
    )
    assert response["media"] == {"photos": [], "videos": [], "audio": []}
    run("git", "pull", "--ff-only", cwd=inspection)
    assert not (inspection / "public" / slug / "videos/large.mp4").exists()
    manifest = json.loads((inspection / "public" / slug / "manifest.json").read_text(encoding="utf-8"))
    assert manifest["media"] == {"photos": [], "videos": [], "audio": []}
    assert not any(tasks.iterdir())

    try:
        PUBLISHER.checkout_bucket(str(root / "missing-bucket.git"), "main", slug, tasks)
    except PUBLISHER.PublishError:
        pass
    else:
        raise AssertionError("Missing bucket repository should fail")
    assert not any(tasks.iterdir()), "failed bucket checkout was not deleted"

print("Dual-repository Memory Page publisher verified.")
