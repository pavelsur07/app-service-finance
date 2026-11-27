#!/usr/bin/env python3
"""Приёмка серверного модуля OneC по HTTP, без 1С.

Запуск: VF_ONEC_URL=https://… VF_ONEC_TOKEN=… python3 tools/onec-smoke/smoke.py [--out DIR] [--big] [--base-id UUID] [--org GUID]

Новое подключение — без --base-id: первое рукопожатие закрепляет новый base_id.
Подключение с уже закреплённой базой — --base-id этой базы, сценарий 1 пропускается.
У организации --org (по умолчанию из примера) владелец должен включить «Загружать»;
вторая организация EXTRA_ORG добавляется в справочник и остаётся без разрешения.
Тела частей пишутся в файлы в --out и отправляются байтами из файла.
Итог — results.json в --out (без токена) и список package_id в stdout.
"""

import argparse
import copy
import datetime as dt
import json
import os
import pathlib
import sys
import time
import urllib.error
import urllib.request
import uuid

EXAMPLES = pathlib.Path(__file__).resolve().parents[2] / "site/src/OneC/Contract/v1/examples"
PAUSE = 1.1
EXTRA_ORG = "5b1d7a2e-9c4f-4e21-8a3b-6d0f2c9e7b14"


def now_rfc3339(delta=dt.timedelta()):
    return (dt.datetime.now().astimezone() + delta).replace(microsecond=0).isoformat()


class Client:
    def __init__(self, url, token):
        self.base = url.rstrip("/") + "/api/onec/v1"
        self.token = token

    def post(self, path, body, token=...):
        token = self.token if token is ... else token
        headers = {"Content-Type": "application/json; charset=utf-8"}
        if token is not None:
            headers["Authorization"] = "Bearer " + token
        for attempt in (1, 2):
            time.sleep(PAUSE)
            req = urllib.request.Request(self.base + path, data=body, headers=headers, method="POST")
            try:
                with urllib.request.urlopen(req, timeout=60) as r:
                    status, raw = r.status, r.read()
            except urllib.error.HTTPError as e:
                status, raw, retry = e.code, e.read(), e.headers.get("Retry-After")
                if status == 429 and attempt == 1:
                    time.sleep(float(retry or 5))
                    continue
            break
        text = raw.decode("utf-8", "replace")
        try:
            parsed = json.loads(text)
        except ValueError:
            parsed = None
        return status, parsed, text


def write(out, name, obj):
    path = out / name
    path.write_bytes(json.dumps(obj, ensure_ascii=False, indent=2).encode("utf-8"))
    return path


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default=f"onec-smoke-{dt.datetime.now():%Y%m%d-%H%M%S}")
    ap.add_argument("--big", action="store_true", help="сценарий 15: часть больше 10 МиБ")
    ap.add_argument("--base-id", help="база, уже закреплённая за подключением")
    ap.add_argument("--org", help="GUID организации с «Загружать»; по умолчанию из примера")
    args = ap.parse_args()
    url, token = os.environ.get("VF_ONEC_URL"), os.environ.get("VF_ONEC_TOKEN")
    if not url or not token:
        sys.exit("нужны VF_ONEC_URL и VF_ONEC_TOKEN")

    out = pathlib.Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    c = Client(url, token)
    hs_example = json.loads((EXAMPLES / "handshake_request.json").read_text("utf-8"))
    part_example = json.loads((EXAMPLES / "package_part.json").read_text("utf-8"))
    base_id = args.base_id or str(uuid.uuid4())
    org = args.org or hs_example["organizations"][0]["guid"]
    snapshot = now_rfc3339()
    results, packages, ctx = [], [], {"base_id": base_id}

    def part(file, **changes):
        body = copy.deepcopy(part_example)
        body.update({"base_id": base_id, "snapshot_at": snapshot, "organization_guids": [org]}, **changes)
        for k, v in list(body.items()):
            if v is ...:
                del body[k]
        return write(out, file, body)

    def check(no, title, expect_status, expect_error, status, parsed, text, extra_ok=True):
        error = parsed.get("error") if isinstance(parsed, dict) else None
        ok = status == expect_status and (expect_error is None or error == expect_error) and extra_ok
        results.append({"no": no, "title": title, "expect": f"{expect_status} {expect_error or ''}".strip(),
                        "status": status, "error": error, "ok": ok, "body": parsed if parsed is not None else text[:2000]})
        print(f"{'OK  ' if ok else 'FAIL'} {no:>4} {title}: {status} {error or ''}")
        return parsed if isinstance(parsed, dict) else {}

    def send_file(path, **kw):
        return c.post("/packages", path.read_bytes(), **kw)

    # 1. Часть до рукопожатия.
    if args.base_id is None:
        check("1", "часть до рукопожатия", 409, "base_conflict", *send_file(part("p1-before-handshake.json", package_id=str(uuid.uuid4()))))

    # 2–3. Рукопожатие и повтор.
    organizations = [o for o in hs_example["organizations"] if o["guid"] != org]
    organizations += [dict(hs_example["organizations"][0], guid=org), {"guid": EXTRA_ORG, "name": "Организация без разрешения (smoke)", "inn": None, "kpp": None}]
    hs = dict(hs_example, base_id=base_id, organizations=organizations)
    hs_bytes = json.dumps(hs, ensure_ascii=False).encode("utf-8")
    r = check("2", "рукопожатие", 200, None, *c.post("/handshake", hs_bytes))
    orgs = r.get("organizations") or []
    ok2 = (r.get("status") == "connected" and (r.get("support") or {}).get("level") == "supported"
           and (r.get("settings") or {}).get("open_outside_period_months") == 12
           and bool(orgs) and all(o.get("mapped") is False and isinstance(o.get("export_allowed"), bool) for o in orgs))
    results[-1]["ok"] = results[-1]["ok"] and ok2
    if not ok2:
        print("     поля ответа не совпали с ожиданием")
    ctx["connection_id"] = r.get("connection_id")
    r3 = check("3", "повтор рукопожатия", 200, None, *c.post("/handshake", hs_bytes))
    results[-1]["ok"] = results[-1]["ok"] and r3.get("connection_id") == ctx["connection_id"]

    allowed = {o.get("guid"): o.get("export_allowed") for o in orgs}
    ctx["export_allowed"] = allowed
    if allowed.get(org) is not True:
        (out / "results.json").write_text(json.dumps({"context": ctx, "results": results}, ensure_ascii=False, indent=2), "utf-8")
        sys.exit(f"у организации {org} не включено «Загружать»: поставьте галочку в карточке подключения и запустите снова")
    hs_check = next(x for x in results if x["no"] == "2")
    hs_check["ok"] = hs_check["ok"] and allowed.get(EXTRA_ORG) is False

    # 4–5. Ошибки рукопожатия.
    other = json.dumps(dict(hs, base_id=str(uuid.uuid4())), ensure_ascii=False).encode("utf-8")
    check("4", "рукопожатие с другим base_id", 409, "base_conflict", *c.post("/handshake", other))
    check("5a", "рукопожатие: невалидный JSON", 422, "invalid_handshake", *c.post("/handshake", b'{"base_id": "' + base_id.encode() + b'",'))
    no_orgs = {k: v for k, v in hs.items() if k != "organizations"}
    check("5b", "рукопожатие без organizations", 422, "invalid_handshake",
          *c.post("/handshake", json.dumps(no_orgs, ensure_ascii=False).encode("utf-8")))

    # 6–8. Приём, повтор, конфликт.
    p6_id = str(uuid.uuid4())
    p6 = part("p6.json", package_id=p6_id)
    r = check("6", "часть 1/1", 200, None, *send_file(p6))
    results[-1]["ok"] = results[-1]["ok"] and r.get("accepted") is True and r.get("status") == "stored" and r.get("duplicate") is False
    packages.append((p6_id, 1, "п. 6, 1 из 1", p6))
    r = check("7", "тот же файл повторно", 200, None, *send_file(p6))
    results[-1]["ok"] = results[-1]["ok"] and r.get("duplicate") is True
    p8 = part("p8-conflict.json", package_id=p6_id, warnings=["Другое предупреждение"])
    check("8", "тот же ключ, другое тело", 409, "package_conflict", *send_file(p8))

    # 9–11. Невалидная оболочка.
    check("9", "без snapshot_at", 422, "invalid_package", *send_file(part("p9.json", package_id=str(uuid.uuid4()), snapshot_at=...)))
    check("10", "snapshot_at +2 дня", 422, "invalid_package",
          *send_file(part("p10.json", package_id=str(uuid.uuid4()), snapshot_at=now_rfc3339(dt.timedelta(days=2)))))
    check("11", "kind bank_statements", 422, "invalid_package", *send_file(part("p11.json", package_id=str(uuid.uuid4()), kind="bank_statements")))

    # 16–17. Организации выгрузки.
    check("16", "organization_guids с неизвестной организацией", 422, "invalid_package",
          *send_file(part("p16.json", package_id=str(uuid.uuid4()), organization_guids=[org, str(uuid.uuid4())])))
    check("17", "organization_guids с организацией без «Загружать»", 422, "invalid_package",
          *send_file(part("p17.json", package_id=str(uuid.uuid4()), organization_guids=[org, EXTRA_ORG])))
    check("18", "без organization_guids", 422, "invalid_package", *send_file(part("p18.json", package_id=str(uuid.uuid4()), organization_guids=...)))

    # 12. Авторизация.
    p12 = part("p12.json", package_id=str(uuid.uuid4()))
    check("12a", "без Authorization", 401, "invalid_token", *send_file(p12, token=None))
    check("12b", "неверный токен", 401, "invalid_token", *send_file(p12, token="0" * 64))

    # 13–14. Выгрузка из двух частей.
    p13_id = str(uuid.uuid4())
    for n in (1, 2):
        path = part(f"p13-part{n}.json", package_id=p13_id, part=n, parts_total=2)
        r = check(f"13.{n}", f"часть {n}/2", 200, None, *send_file(path))
        results[-1]["ok"] = results[-1]["ok"] and r.get("status") == "stored" and r.get("duplicate") is False
        packages.append((p13_id, n, f"п. 13, {n} из 2", path))
    p14 = part("p14.json", package_id=p13_id, part=2, parts_total=2, period_to="2026-11-29")
    check("14", "часть 2/2 с другим period_to", 409, None, *send_file(p14))
    p19_id = str(uuid.uuid4())
    path = part("p19-part1.json", package_id=p19_id, part=1, parts_total=2)
    check("19.1", "выгрузка 2 части: часть 1", 200, None, *send_file(path))
    packages.append((p19_id, 1, "п. 19, 1 из 2", path))
    check("19.2", "часть 2 с другим organization_guids", 409, "package_conflict",
          *send_file(part("p19-part2.json", package_id=p19_id, part=2, parts_total=2, organization_guids=[org, EXTRA_ORG])))

    # 15. Слишком большая часть.
    if args.big:
        body = copy.deepcopy(part_example)
        body.update(base_id=base_id, snapshot_at=snapshot, organization_guids=[org], package_id=str(uuid.uuid4()), warnings=["x" * (10 * 1024 * 1024 + 1024)])
        p15 = write(out, "p15-big.json", body)
        check("15", "часть больше 10 МиБ", 413, "payload_too_large", *send_file(p15))

    ctx["packages"] = [{"package_id": p, "part": n, "note": note, "file": f.name, "sha256": __import__("hashlib").sha256(f.read_bytes()).hexdigest()}
                       for p, n, note, f in packages]
    (out / "results.json").write_text(json.dumps({"context": ctx, "results": results}, ensure_ascii=False, indent=2), "utf-8")

    print(f"\nconnection_id: {ctx['connection_id']}\nbase_id: {base_id}")
    for p in ctx["packages"]:
        print(f"package_id {p['package_id']} part {p['part']} ({p['note']}) sha256 {p['sha256']}")
    failed = [r["no"] for r in results if not r["ok"]]
    print(f"\nпрошло {len(results) - len(failed)} из {len(results)}" + (f", не прошли: {', '.join(failed)}" if failed else ""))
    print(f"файлы и results.json: {out.resolve()}")


if __name__ == "__main__":
    main()
