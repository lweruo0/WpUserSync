#!/usr/bin/env python3
"""
Testskript für das Admidio-Plugin WpUserSync.

Funktionen:
- sendet einen JSON-POST an /api/users.php
- setzt Bearer-Token
- kann Payload direkt aus Datei laden oder Beispielpayload verwenden
- optional: TLS-Zertifikatprüfung deaktivieren (--insecure)
- gibt Statuscode, Header und JSON-/Text-Antwort aus

Beispiele:

    python3 test_admidio_wp_user_sync.py \
        --url "https://example.org/adm_plugins/WpUserSync/api/users.php" \
        --token "mein-geheimes-token" \
        --payload payload-example.json

"""

from __future__ import annotations

import argparse
import json
import ssl
import sys
from pathlib import Path
from typing import Any
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Testskript für Admidio WpUserSync API")
    parser.add_argument("--url", default='https://mitgliederverwaltung.bfv-ehingen.de/adm_plugins/wpusersync/api/write_user.php', help="Vollständige URL zum Endpoint /api/users.php")
    parser.add_argument("--token", default = 'ylL4JNQBrTE5Qy9hYAhSMiFw99vyZefeks3efG8TeSuYFyOUMfYecDdzMthxJ6DPfVVw5u7lNR9pzrgYumS07g', help="Bearer-Token im Klartext")
    parser.add_argument("--payload", default='payload.json', help="Pfad zu einer JSON-Datei mit dem Request-Body")
    parser.add_argument("--timeout", type=int, default=30, help="Timeout in Sekunden (Default: 30)")
    return parser.parse_args()




def load_payload(path: str) -> dict[str, Any]:
    payload_path = Path(path)
    if not payload_path.is_file():
        raise FileNotFoundError(f"Payload-Datei nicht gefunden: {payload_path}")
    try:
        return json.loads(payload_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise ValueError(f"Ungültiges JSON in {payload_path}: {exc}") from exc



def send_request(url: str, token: str, payload: dict[str, Any], timeout: int) -> tuple[int, str, dict[str, str]]:
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    request = Request(
        url=url,
        data=body,
        method="POST",
        headers={
            "X-Api-Token": token,
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "WpUserSync-TestClient/1.0",
        },
    )

    context = None

    try:
        with urlopen(request, timeout=timeout, context=context) as response:
            status = response.status
            text = response.read().decode("utf-8", errors="replace")
            headers = {k: v for k, v in response.headers.items()}
            return status, text, headers
    except HTTPError as exc:
        text = exc.read().decode("utf-8", errors="replace")
        headers = {k: v for k, v in exc.headers.items()}
        return exc.code, text, headers
    except URLError as exc:
        raise ConnectionError(f"Verbindungsfehler: {exc}") from exc



def try_parse_json(text: str) -> Any:
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        return None



def main() -> int:
    args = parse_args()

    try:
        payload = load_payload(args.payload)
    except Exception as exc:
        print(f"Fehler beim Erzeugen/Laden des Payloads: {exc}", file=sys.stderr)
        return 2

    print("=== Request ===")
    print(f"URL: {args.url}")
    print("Content-Type: application/json")
    print("Body:")
    print(json.dumps(payload, ensure_ascii=False, indent=2))
    print()

    try:
        status, response_text, headers = send_request(
            url=args.url,
            token=args.token,
            payload=payload,
            timeout=args.timeout,
        )
    except Exception as exc:
        print(f"Fehler beim Request: {exc}", file=sys.stderr)
        return 3

    print("=== Response ===")
    print(f"HTTP-Status: {status}")
    print("Headers:")
    for key, value in headers.items():
        print(f"  {key}: {value}")

    parsed = try_parse_json(response_text)
    print("Body:")
    if parsed is not None:
        print(json.dumps(parsed, ensure_ascii=False, indent=2))
    else:
        print(response_text)

    if 200 <= status < 300:
        return 0
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
