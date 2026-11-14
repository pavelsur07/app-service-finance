"""Deterministic offline export and fail-closed, checkout-independent runtime assets.

Export/check: python -m ai_service.evals.payment_intake_assets [--check] [--root EVALS_DIR]
The canonical docs/contracts are read only by export_pack, never by load_pack.
"""

import argparse
import hashlib
import json
import os
import re
from pathlib import Path
from typing import Any

from ai_service.evals import payment_intake_dataset as curated
from ai_service.evals import payment_intake_semantics as semantics
from ai_service.tasks.parse_payment_message.schemas import PaymentInput

BASE_FILES = {
    "source41.jsonl",
    "source-corpus.jsonl",
    "source-review.jsonl",
    "semantic12.jsonl",
    "schema.json",
    "reviewed-index.json",
}
CONTRACT_ID = "https://vashfindir.ru/contracts/ai-parse-payment-message/v1/schema.json"
# Published V1 contracts are immutable; verify the deployed copy without a checkout.
CONTRACT_SHA256 = "904fbed170cdc02f503a66d72dd7cb0cbe20be32cdbda8605b47d9b791034d8a"


def _directory(root: Path | None) -> Path:
    if root is None:
        root = Path(os.environ.get("AI_SERVICE_EVALS_DIR", Path(__file__).resolve().parents[3] / "evals"))
    return root / "parse_payment_message/runtime"


def _digest(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def _json(value: Any) -> bytes:
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2) + "\n").encode()


def _jsonl(cases: list[dict[str, Any]]) -> bytes:
    return ("\n".join(json.dumps(case, ensure_ascii=False, sort_keys=True) for case in cases) + "\n").encode()


def _dataset(cases: list[dict[str, Any]], corpus: str, review: str | None, gold: str) -> dict[str, Any]:
    families: dict[str, list[str]] = {}
    for case in cases:
        families.setdefault(case["family"], []).append(case["id"])
    return {"N": len(cases), "families": families, "corpus_file": corpus, "review_file": review, "gold": gold}


def export_pack(root: Path | None = None, *, check: bool = False) -> None:
    """Compile from canonical originals. --check is read-only and catches any source drift."""
    from ai_service.evals import payment_intake as runner

    canonical = runner.ROOT / "evals/parse_payment_message"
    schema = runner.ROOT.parent / "_contracts/ai-parse-payment-message/v1/schema.json"
    source = runner.load_holdout(runner.CORPUS, runner.REVIEW)
    semantic = semantics.load_cases(semantics.SEMANTIC_CORPUS)
    index = curated.load_reviewed_index(canonical / "reviewed-index.json")
    files = {
        "source41.jsonl": _jsonl(source),
        "source-corpus.jsonl": runner.CORPUS.read_bytes(),
        "source-review.jsonl": runner.REVIEW.read_bytes(),
        "semantic12.jsonl": semantics.SEMANTIC_CORPUS.read_bytes(),
        "schema.json": schema.read_bytes(),
        "reviewed-index.json": (canonical / "reviewed-index.json").read_bytes(),
    }
    datasets = {
        "source": _dataset(source, "source-corpus.jsonl", "source-review.jsonl", "partial_annotations"),
        "semantic": _dataset(semantic, "semantic12.jsonl", None, "synthetic_explicit_choices"),
    }
    for entry in index["datasets"]:
        cases = curated.load_reviewed_dataset(canonical / entry["path"])
        files[entry["path"]] = (canonical / entry["path"]).read_bytes()
        datasets["reviewed:" + entry["version"]] = _dataset(
            cases, entry["path"], entry["path"], "human_reviewed_explicit_choices"
        )
    metadata = {
        "format_version": 1,
        "contract_id": CONTRACT_ID,
        "files": {name: _digest(value) for name, value in files.items()},
        "datasets": datasets,
        "provenance": {
            "source": "docs/tasks/telegram-payment-intake/examples/messages.jsonl",
            "review": "services/ai-service/evals/parse_payment_message/corpus-review.jsonl",
            "semantic": "services/ai-service/evals/parse_payment_message/semantic-cases.jsonl",
            "schema": "services/_contracts/ai-parse-payment-message/v1/schema.json",
        },
    }
    files["manifest.json"] = _json(metadata)
    target = _directory(root)
    if check:
        if (
            not target.is_dir()
            or {p.name for p in target.iterdir()} != set(files)
            or any(
                (target / name).is_symlink() or (target / name).read_bytes() != value for name, value in files.items()
            )
        ):
            raise ValueError("runtime_pack_drift")
        load_pack(root)
        return
    # Once a reviewed version exists, changing its contents requires a new version.
    old_path = target / "reviewed-index.json"
    if old_path.is_file():
        old_index = curated.read_json(old_path.read_text(encoding="utf-8"))
        old_versions = {entry["version"]: entry["sha256"] for entry in old_index["datasets"]}
        if any(old_versions.get(entry["version"], entry["sha256"]) != entry["sha256"] for entry in index["datasets"]):
            raise ValueError("reviewed_version_changed")
    target.mkdir(parents=True, exist_ok=True)
    for name, value in files.items():
        if (target / name).is_symlink():
            raise ValueError("invalid_runtime_pack")
        (target / name).write_bytes(value)
    load_pack(root)


def load_pack(root: Path | None = None) -> dict[str, Any]:
    """Return verified datasets and manifest from EVALS_DIR; no checkout or network reads."""
    from ai_service.evals import payment_intake as runner

    try:
        directory = _directory(root)
        manifest_path = directory / "manifest.json"
        if directory.is_symlink() or manifest_path.is_symlink():
            raise ValueError
        metadata = curated.read_json(manifest_path.read_text(encoding="utf-8"))
        if not isinstance(metadata, dict) or set(metadata) != {
            "format_version",
            "contract_id",
            "files",
            "datasets",
            "provenance",
        }:
            raise ValueError
        if (
            type(metadata["format_version"]) is not int
            or metadata["format_version"] != 1
            or metadata["contract_id"] != CONTRACT_ID
        ):
            raise ValueError
        hashes = metadata["files"]
        if not isinstance(hashes, dict) or not hashes.keys() >= BASE_FILES:
            raise ValueError
        for name, digest in hashes.items():
            if (
                not isinstance(name, str)
                or not re.fullmatch(r"[a-z0-9][a-z0-9_.-]{0,110}", name)
                or not isinstance(digest, str)
                or not re.fullmatch(r"[0-9a-f]{64}", digest)
                or (directory / name).is_symlink()
                or _digest((directory / name).read_bytes()) != digest
            ):
                raise ValueError
        if {p.name for p in directory.iterdir()} != {*hashes, "manifest.json"}:
            raise ValueError
        if hashes["schema.json"] != CONTRACT_SHA256:
            raise ValueError
        schema = curated.read_json((directory / "schema.json").read_text(encoding="utf-8"))
        if (
            not isinstance(schema, dict)
            or schema.get("$id") != CONTRACT_ID
            or schema.get("$schema") != "https://json-schema.org/draft/2020-12/schema"
            or not isinstance(schema.get("$defs"), dict)
            or not {"Payload", "Output"} <= schema["$defs"].keys()
        ):
            raise ValueError
        provenance = metadata["provenance"]
        if not isinstance(provenance, dict) or set(provenance) != {"source", "review", "semantic", "schema"}:
            raise ValueError
        if any(
            not isinstance(value, str) or not value or ".." in value.split("/") or value.startswith("/")
            for value in provenance.values()
        ):
            raise ValueError
        source = runner.load_holdout(directory / "source-corpus.jsonl", directory / "source-review.jsonl")
        for case in source:
            PaymentInput.model_validate(runner._payload(case))
        compiled = [
            curated.read_json(line)
            for line in (directory / "source41.jsonl").read_text(encoding="utf-8").splitlines()
            if line.strip()
        ]
        if compiled != source:
            raise ValueError
        semantic = semantics.load_cases(directory / "semantic12.jsonl")
        index = curated.load_reviewed_index(directory / "reviewed-index.json")
        if set(hashes) != BASE_FILES | {entry["path"] for entry in index["datasets"]}:
            raise ValueError
        datasets = {"source": source, "semantic": semantic}
        expected = {
            "source": _dataset(source, "source-corpus.jsonl", "source-review.jsonl", "partial_annotations"),
            "semantic": _dataset(semantic, "semantic12.jsonl", None, "synthetic_explicit_choices"),
        }
        for entry in index["datasets"]:
            key = "reviewed:" + entry["version"]
            datasets[key] = curated.load_reviewed_dataset(directory / entry["path"])
            expected[key] = _dataset(datasets[key], entry["path"], entry["path"], "human_reviewed_explicit_choices")
        if metadata["datasets"] != expected:
            raise ValueError
    except (OSError, ValueError, TypeError, KeyError, AttributeError):
        raise ValueError("invalid_runtime_pack") from None
    return {"datasets": datasets, "manifest": metadata}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path)
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--validate-dataset", type=Path)
    args = parser.parse_args(argv)
    try:
        if args.validate_dataset:
            curated.load_reviewed_dataset(args.validate_dataset)
        else:
            export_pack(args.root, check=args.check)
    except (OSError, ValueError, TypeError, KeyError):
        print(json.dumps({"error": "invalid_dataset_or_runtime_pack"}))  # noqa: T201
        return 2
    print(json.dumps({"status": "validated"}))  # noqa: T201
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
