#!/usr/bin/env python3
"""
Testskript für das Admidio-Plugin WpUserSync.

Schreibt einen Benutzer per write_user.php und liest die Profildaten
anschließend per read_user.php zurück (Suchfelder aus demselben Payload).

Beispiel:

    python test_admidio_wp_user_sync.py

    python test_admidio_wp_user_sync.py \
        --base-url "https://example.org/adm_plugins/WpUserSync/api" \
        --token "mein-geheimes-token" \
        --payload payload.json
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import sys
import time
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

DEFAULT_BASE_URL = "https://mitgliederverwaltung.bfv-ehingen.de/adm_plugins/wpusersync/api"
DEFAULT_TOKEN = ("mein-geheimes-token")  
READ_PROFILE_KEYS = ("FIRST_NAME", "LAST_NAME", "BIRTHDAY")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Testskript für Admidio WpUserSync API")
    parser.add_argument(
        "--base-url",
        default=DEFAULT_BASE_URL,
        help="Basis-URL zum API-Verzeichnis (ohne Dateiname)",
    )
    parser.add_argument("--token", default=DEFAULT_TOKEN, help="API-Token im Klartext")
    parser.add_argument("--payload", default="payload.json", help="Pfad zur JSON-Datei mit dem Request-Body")
    parser.add_argument("--timeout", type=int, default=30, help="Timeout in Sekunden (Default: 30)")
    return parser.parse_args()


def api_url(base_url: str, endpoint: str) -> str:
    return f"{base_url.rstrip('/')}/{endpoint}"


def load_payload(path: str) -> dict[str, Any]:
    payload_path = Path(path)
    if not payload_path.is_file():
        raise FileNotFoundError(f"Payload-Datei nicht gefunden: {payload_path}")
    try:
        return json.loads(payload_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise ValueError(f"Ungültiges JSON in {payload_path}: {exc}") from exc


def generate_nonce(token: str) -> str:
    """Erzeugt X-Api-Nonce: unixzeit.hmac_sha256(unixzeit, sha256(token))."""
    key = hashlib.sha256(token.encode("utf-8")).digest()
    timestamp = str(int(time.time()))
    signature = hmac.new(key, timestamp.encode("ascii"), hashlib.sha256).hexdigest()
    return f"{timestamp}.{signature}"


def build_read_payload(payload: dict[str, Any]) -> dict[str, Any]:
    profile = payload.get("profile")
    if not isinstance(profile, dict):
        raise ValueError("Payload enthält kein 'profile'-Objekt.")

    read_profile: dict[str, Any] = {}
    missing: list[str] = []
    for key in READ_PROFILE_KEYS:
        value = profile.get(key)
        if value is None or str(value).strip() == "":
            missing.append(key)
        else:
            read_profile[key] = value

    if missing:
        raise ValueError(
            "Für read_user.php fehlen im profile-Objekt: " + ", ".join(missing)
        )

    return {"profile": read_profile}


def send_request(
    url: str,
    token: str,
    nonce: str,
    payload: dict[str, Any],
    timeout: int,
) -> tuple[int, str, dict[str, str]]:
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    request = Request(
        url=url,
        data=body,
        method="POST",
        headers={
            "X-Api-Token": token,
            "X-Api-Nonce": nonce,
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "WpUserSync-TestClient/1.0",
        },
    )

    try:
        with urlopen(request, timeout=timeout) as response:
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


def print_exchange(label: str, url: str, payload: dict[str, Any], status: int, headers: dict[str, str], response_text: str) -> None:
    print(f"=== {label} ===")
    print(f"URL: {url}")
    print("Request-Body:")
    print(json.dumps(payload, ensure_ascii=False, indent=2))
    print()
    print(f"HTTP-Status: {status}")
    print("Response-Headers:")
    for key, value in headers.items():
        print(f"  {key}: {value}")

    parsed = try_parse_json(response_text)
    print("Response-Body:")
    if parsed is not None:
        print(json.dumps(parsed, ensure_ascii=False, indent=2))
    else:
        print(response_text)
    print()


def main() -> int:
    args = parse_args()
    write_url = api_url(args.base_url, "write_user.php")
    read_url = api_url(args.base_url, "read_user.php")

    try:
        payload = load_payload(args.payload)
        read_payload = build_read_payload(payload)
    except Exception as exc:
        print(f"Fehler beim Laden/Aufbereiten des Payloads: {exc}", file=sys.stderr)
        return 2

    try:
        write_nonce = generate_nonce(args.token)
        write_status, write_text, write_headers = send_request(
            url=write_url,
            token=args.token,
            nonce=write_nonce,
            payload=payload,
            timeout=args.timeout,
        )
    except Exception as exc:
        print(f"Fehler beim Schreib-Request: {exc}", file=sys.stderr)
        return 3

    print_exchange("Schreiben (write_user.php)", write_url, payload, write_status, write_headers, write_text)

    if not 200 <= write_status < 300:
        return 1

    try:
        read_nonce = generate_nonce(args.token)
        read_status, read_text, read_headers = send_request(
            url=read_url,
            token=args.token,
            nonce=read_nonce,
            payload=read_payload,
            timeout=args.timeout,
        )
    except Exception as exc:
        print(f"Fehler beim Lese-Request: {exc}", file=sys.stderr)
        return 4

    print_exchange("Zurücklesen (read_user.php)", read_url, read_payload, read_status, read_headers, read_text)



    if 200 <= read_status < 300:
        return 0
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
