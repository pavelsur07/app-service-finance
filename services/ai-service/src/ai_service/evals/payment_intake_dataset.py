"""Offline V1 human-reviewed additions; metadata never enters the model payload."""

import hashlib
import json
import re
from datetime import date
from pathlib import Path
from typing import Any

from ai_service.evals import payment_intake_semantics as semantics


def unique_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError
        result[key] = value
    return result


def read_json(text: str) -> Any:
    return json.loads(text, object_pairs_hook=unique_object)


def load_reviewed_dataset(path: Path) -> list[dict[str, Any]]:
    """Require all gold fields, unique verifiable monetary anchors and explicit human review.

    Any pending candidate rejects the registered file; candidate work stays outside runtime assets.
    User corrections and model guesses cannot label gold.
    Unknown amounts require a question and a verifiable monetary anchor.
    Repeated monetary amounts/ambiguous anchors are unsupported by the existing scorer.
    """
    try:
        cases: list[dict[str, Any]] = []
        for line in path.read_text(encoding="utf-8").splitlines():
            if not line.strip():
                continue
            row = read_json(line)
            if not isinstance(row, dict) or set(row) != {"format_version", "source", "review", "case"}:
                raise ValueError
            if type(row["format_version"]) is not int or row["format_version"] != 1:
                raise ValueError
            source, review = row["source"], row["review"]
            if not isinstance(source, dict) or set(source) != {"channel", "provenance", "annotation_origin"}:
                raise ValueError
            if (
                not isinstance(source["channel"], str)
                or not re.fullmatch(r"[a-z][a-z0-9_-]{0,63}", source["channel"])
                or source["provenance"] not in ("synthetic", "anonymized")
                or source["annotation_origin"] != "human"
            ):
                raise ValueError
            if not isinstance(review, dict) or set(review) != {"status", "reviewer_ref", "reviewed_at"}:
                raise ValueError
            if (
                review["status"] != "human_reviewed"
                or not isinstance(review["reviewer_ref"], str)
                or not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}", review["reviewer_ref"])
                or not isinstance(review["reviewed_at"], str)
                or date.fromisoformat(review["reviewed_at"]).isoformat() != review["reviewed_at"]
            ):
                raise ValueError
            case = row["case"]
            if (
                not isinstance(case, dict)
                or not isinstance(case.get("id"), str)
                or not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_.-]{0,95}", case["id"])
            ):
                raise ValueError
            semantics._validate_case(case, allow_unknown_amount=True)
            cases.append(case)
        if not cases or len({case["id"] for case in cases}) != len(cases):
            raise ValueError
    except (OSError, ValueError, TypeError, KeyError, AttributeError):
        raise ValueError("invalid_reviewed_dataset") from None
    return cases


def load_reviewed_index(path: Path) -> dict[str, Any]:
    """A flat, hash-pinned, immutable-version index; no caller-provided external paths."""
    try:
        index = read_json(path.read_text(encoding="utf-8"))
        if not isinstance(index, dict) or set(index) != {"format_version", "datasets"}:
            raise ValueError
        if type(index["format_version"]) is not int or index["format_version"] != 1:
            raise ValueError
        if not isinstance(index["datasets"], list):
            raise ValueError
        versions: set[str] = set()
        paths: set[str] = set()
        for entry in index["datasets"]:
            if not isinstance(entry, dict) or set(entry) != {"version", "path", "sha256"}:
                raise ValueError
            version, name, digest = entry["version"], entry["path"], entry["sha256"]
            if (
                not isinstance(version, str)
                or not re.fullmatch(r"[a-z0-9][a-z0-9_.-]{0,63}", version)
                or version in versions
                or not isinstance(name, str)
                or not re.fullmatch(r"reviewed-[a-z0-9][a-z0-9_.-]{0,90}\.jsonl", name)
                or name in paths
                or not isinstance(digest, str)
                or not re.fullmatch(r"[0-9a-f]{64}", digest)
            ):
                raise ValueError
            dataset_path = path.parent / name
            if dataset_path.is_symlink() or hashlib.sha256(dataset_path.read_bytes()).hexdigest() != digest:
                raise ValueError
            load_reviewed_dataset(dataset_path)
            versions.add(version)
            paths.add(name)
    except (OSError, ValueError, TypeError, KeyError):
        raise ValueError("invalid_reviewed_index") from None
    return index
