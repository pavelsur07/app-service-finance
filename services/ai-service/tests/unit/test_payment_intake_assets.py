"""Runtime fixtures must work with only a package and a detached evals directory."""

import importlib
import json
import shutil
from pathlib import Path
from typing import Any

import pytest

from ai_service.evals import payment_intake as runner
from ai_service.evals import payment_intake_semantics as semantics


def assets() -> Any:
    return importlib.import_module("ai_service.evals.payment_intake_assets")


def test_exported_pack_roundtrip_without_checkout(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    source, semantic = runner.load_holdout(), semantics.load_cases()
    assets().export_pack(tmp_path)
    monkeypatch.setenv("AI_SERVICE_EVALS_DIR", str(tmp_path))
    monkeypatch.setattr(runner, "CORPUS", Path("/missing/source.jsonl"))
    monkeypatch.setattr(runner, "REVIEW", Path("/missing/review.jsonl"))
    monkeypatch.setattr(semantics, "SEMANTIC_CORPUS", Path("/missing/semantic.jsonl"))
    pack = assets().load_pack()
    assert pack["datasets"] == {"source": source, "semantic": semantic}
    assert runner.load_holdout() == source
    assert semantics.load_cases() == semantic
    metadata = runner.manifest(source)
    assert metadata["N"] == 41
    assert metadata["schema_sha256"] == pack["manifest"]["files"]["schema.json"]
    assert metadata["corpus_sha256"] == pack["manifest"]["files"]["source-corpus.jsonl"]
    assert metadata["gold"] == "partial_annotations"
    assert runner.manifest(semantic, "semantic")["gold"] == "synthetic_explicit_choices"


def test_export_is_deterministic_and_check_detects_drift(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    assets().export_pack(tmp_path)
    original = {p.name: p.read_bytes() for p in (tmp_path / "parse_payment_message/runtime").iterdir()}
    assets().export_pack(tmp_path, check=True)
    assets().export_pack(tmp_path)
    assert original == {p.name: p.read_bytes() for p in (tmp_path / "parse_payment_message/runtime").iterdir()}
    source = tmp_path / "changed-source.jsonl"
    source.write_text(runner.CORPUS.read_text() + "\n")
    monkeypatch.setattr(runner, "CORPUS", source)
    with pytest.raises(ValueError, match=r"^runtime_pack_drift$"):
        assets().export_pack(tmp_path, check=True)


@pytest.mark.parametrize(
    "name",
    [
        "manifest.json",
        "schema.json",
        "source41.jsonl",
        "source-corpus.jsonl",
        "source-review.jsonl",
        "semantic12.jsonl",
        "reviewed-index.json",
    ],
)
@pytest.mark.parametrize("mutation", ["missing", "tampered"])
def test_incomplete_or_changed_pack_is_rejected(tmp_path: Path, name: str, mutation: str) -> None:
    assets().export_pack(tmp_path)
    path = tmp_path / "parse_payment_message/runtime" / name
    if mutation == "missing":
        path.unlink()
    else:
        path.write_text("sensitive-raw-token")
    with pytest.raises(ValueError, match=r"^invalid_runtime_pack$") as caught:
        assets().load_pack(tmp_path)
    assert caught.value.__cause__ is None


@pytest.mark.parametrize(
    "mutation", ["path", "count", "empty", "version", "schema_shape", "duplicate_json_key", "symlink"]
)
def test_manifest_and_schema_structure_are_validated(tmp_path: Path, mutation: str) -> None:
    assets().export_pack(tmp_path)
    root = tmp_path / "parse_payment_message/runtime"
    path = root / "manifest.json"
    manifest = json.loads(path.read_text())
    if mutation == "path":
        manifest["files"]["../outside"] = "0" * 64
    elif mutation == "count":
        manifest["datasets"]["source"]["N"] = 40
    elif mutation == "empty":
        manifest["datasets"] = {}
    elif mutation == "version":
        manifest["format_version"] = 2
    elif mutation == "schema_shape":
        (root / "schema.json").write_text("{}")
        manifest["files"]["schema.json"] = runner.digest("{}")
    elif mutation == "duplicate_json_key":
        path.write_text('{"format_version":1,"format_version":2}')
    else:
        target = tmp_path / "external.json"
        shutil.copy(root / "schema.json", target)
        (root / "schema.json").unlink()
        (root / "schema.json").symlink_to(target)
    if mutation != "duplicate_json_key":
        path.write_text(json.dumps(manifest))
    with pytest.raises(ValueError, match=r"^invalid_runtime_pack$"):
        assets().load_pack(tmp_path)


@pytest.mark.parametrize("mutation", ["missing", "tampered"])
def test_runner_checks_assets_before_adapter_construction(
    mutation: str, tmp_path: Path, monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]
) -> None:
    if mutation == "tampered":
        assets().export_pack(tmp_path)
        (tmp_path / "parse_payment_message/runtime/semantic12.jsonl").write_text("sensitive-raw-token")
    monkeypatch.setenv("AI_SERVICE_EVALS_DIR", str(tmp_path))

    def forbidden(*args: Any, **kwargs: Any) -> None:
        pytest.fail("network adapter constructed for missing assets")

    monkeypatch.setattr(runner, "build_adapters", forbidden)
    assert (
        runner.main(
            [
                "--execute",
                "--routes",
                str(tmp_path / "routes.yaml"),
                "--model",
                "stub/payment",
                "--max-calls",
                "1",
                "--max-input-tokens",
                "65536",
                "--max-output-tokens",
                "16000",
                "--budget-rub",
                "1",
                "--input-price",
                "0",
                "--output-price",
                "0",
            ]
        )
        == 2
    )
    assert json.loads(capsys.readouterr().out)["error"] == "runner_error"


def test_reviewed_pack_cli_and_manifest_keep_their_own_identity(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]
) -> None:
    from ai_service.evals.payment_intake_dataset import load_reviewed_dataset

    canonical = tmp_path / "canonical/services/ai-service"
    canonical_evals = canonical / "evals/parse_payment_message"
    canonical_evals.mkdir(parents=True)
    schema_dir = canonical.parent / "_contracts/ai-parse-payment-message/v1"
    schema_dir.mkdir(parents=True)
    shutil.copy(runner.ROOT.parent / "_contracts/ai-parse-payment-message/v1/schema.json", schema_dir)
    row = {
        "format_version": 1,
        "source": {"channel": "manual", "provenance": "synthetic", "annotation_origin": "human"},
        "review": {"status": "human_reviewed", "reviewer_ref": "review-team", "reviewed_at": "2026-11-14"},
        "case": semantics.load_cases()[0],
    }
    row["case"]["id"] = "arbitrary-case-id"
    dataset = canonical_evals / "reviewed-release1.jsonl"
    dataset.write_text(json.dumps(row, ensure_ascii=False) + "\n")
    index = {
        "format_version": 1,
        "datasets": [{"version": "release1", "path": dataset.name, "sha256": runner.digest(dataset.read_text())}],
    }
    (canonical_evals / "reviewed-index.json").write_text(json.dumps(index))
    monkeypatch.setattr(runner, "ROOT", canonical)
    target = tmp_path / "detached-evals"
    monkeypatch.setenv("AI_SERVICE_EVALS_DIR", str(target))
    assets().export_pack()
    pack = assets().load_pack()
    assert pack["datasets"]["reviewed:release1"] == load_reviewed_dataset(dataset)
    assert runner.main(["--dataset", "reviewed:release1"]) == 0
    report = json.loads(capsys.readouterr().out)
    assert report["manifest"]["dataset"] == "reviewed:release1"
    assert report["manifest"]["N"] == 1
    assert report["manifest"]["gold"] == "human_reviewed_explicit_choices"
    assert report["manifest"]["corpus_sha256"] == runner.digest(dataset.read_text())
    payload = runner._payload(row["case"], "reviewed:release1")
    assert payload == row["case"]["payload"]
    assert "expected_items" not in payload
    answer = semantics._canonical(row["case"], row["case"]["expected_items"]).model_dump(mode="json")
    scores = runner._score([row["case"]], {row["case"]["id"]: answer}, "reviewed:release1")
    assert scores["synthetic_exact_row_tuple"] == {"numerator": 1, "denominator": 1}
    row["source"]["channel"] = "email"
    dataset.write_text(json.dumps(row) + "\n")
    index["datasets"][0]["sha256"] = runner.digest(dataset.read_text())
    (canonical_evals / "reviewed-index.json").write_text(json.dumps(index))
    with pytest.raises(ValueError, match=r"^reviewed_version_changed$"):
        assets().export_pack()


def test_detached_runtime_package_uses_assets_without_checkout(tmp_path: Path) -> None:
    import os
    import subprocess
    import sys
    import zipfile

    assets().export_pack(tmp_path / "evals")
    installed = tmp_path / "installed"
    if wheel_path := os.environ.get("PAYMENT_INTAKE_TEST_WHEEL"):
        with zipfile.ZipFile(wheel_path) as wheel:
            wheel.extractall(installed)
    else:
        shutil.copytree(Path(runner.__file__).parents[1], installed / "ai_service")
    script = """
import json
import os
import sys
from pathlib import Path
sys.path = [path for path in sys.path if not path.startswith(("/app", "/repo"))]
from ai_service.evals import payment_intake as runner
from ai_service.evals.payment_intake_assets import load_pack
original_read = Path.open
def guarded_open(path, *args, **kwargs):
    if str(path.resolve()).startswith(("/app/", "/repo/")):
        raise AssertionError("checkout_read")
    return original_read(path, *args, **kwargs)
Path.open = guarded_open
assert "/installed/" in runner.__file__
pack = load_pack()
assert {key: len(cases) for key, cases in pack["datasets"].items()} == {"source":41,"semantic":12}
assert runner.manifest(pack["datasets"]["source"])["N"] == 41
assert runner.manifest(pack["datasets"]["semantic"], "semantic")["N"] == 12
os.environ["AI_SERVICE_EVALS_DIR"] += "/missing"
def forbidden(*args, **kwargs):
    raise AssertionError("adapter_constructed")
runner.build_adapters = forbidden
assert runner.main(["--execute"]) == 2
print("installed_wheel_runtime_pack_ok")
"""
    env = {**os.environ, "PYTHONPATH": str(installed), "AI_SERVICE_EVALS_DIR": str(tmp_path / "evals")}
    result = subprocess.run(  # noqa: S603 — script and paths are test-owned, no network.
        [sys.executable, "-c", script],
        cwd=tmp_path,
        env=env,
        capture_output=True,
        text=True,
        timeout=45,
    )
    assert result.returncode == 0, result.stderr
    assert "installed_wheel_runtime_pack_ok" in result.stdout


@pytest.mark.parametrize("failure", ["tampered_assets", "unknown_dataset"])
def test_direct_evaluate_preflights_before_adapter_call(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch, failure: str
) -> None:
    from decimal import Decimal

    assets().export_pack(tmp_path)
    cases = assets().load_pack(tmp_path)["datasets"]["source"][:1]
    monkeypatch.setenv("AI_SERVICE_EVALS_DIR", str(tmp_path))
    if failure == "tampered_assets":
        (tmp_path / "parse_payment_message/runtime/schema.json").write_text("sensitive-raw-token")

    class Forbidden:
        def complete(self, *args: Any, **kwargs: Any) -> Any:
            pytest.fail("adapter called before runtime preflight")

    limits = runner.Limits(1, 65536, 16000, Decimal("1"), Decimal(0), Decimal(0))
    with pytest.raises((ValueError, KeyError)):
        runner.evaluate(
            cases, Forbidden(), "stub/payment", limits, dataset="source" if failure == "tampered_assets" else "unknown"
        )
