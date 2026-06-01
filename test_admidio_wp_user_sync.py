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
        --token "mein-geheimes-token"

    python3 test_admidio_wp_user_sync.py \
        --url "https://example.org/adm_plugins/WpUserSync/api/users.php" \
        --token "mein-geheimes-token" \
        --payload payload-example.json

    python3 test_admidio_wp_user_sync.py \
        --url "https://example.org/adm_plugins/WpUserSync/api/users.php" \
        --token "mein-geheimes-token" \
        --external-id "wp:4711" \
        --email "max.mustermann@example.org" \
        --first-name "Max" \
        --last-name "Mustermann" \
        --username "max.mustermann" \
        --role member \
        --profile PHONE="+49 123 456789" \
        --profile CITY="Ehingen"
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
    parser.add_argument("--url", default='https://mitgliederverwaltung.bfv-ehingen.de/adm_plugins/wpusersync/api/users.php', help="Vollständige URL zum Endpoint /api/users.php")
    parser.add_argument("--token", default = 'ylL4JNQBrTE5Qy9hYAhSMiFw99vyZefeks3efG8TeSuYFyOUMfYecDdzMthxJ6DPfVVw5u7lNR9pzrgYumS07g', help="Bearer-Token im Klartext")
    parser.add_argument("--payload", default='payload.json', help="Pfad zu einer JSON-Datei mit dem Request-Body")
    parser.add_argument("--timeout", type=int, default=30, help="Timeout in Sekunden (Default: 30)")
    parser.add_argument("--insecure", action="store_true", help="TLS-Zertifikatsprüfung deaktivieren")
    parser.add_argument("--pretty", action="store_true", help="Antwort formatiert ausgeben")

    # Felder für den Direktaufruf ohne Payload-Datei
    parser.add_argument("--external-id", default="wp:123", help="Externe ID, z. B. wp:123")
    parser.add_argument("--email", default="max.mustermann@example.org", help="E-Mail-Adresse")
    parser.add_argument("--first-name", default="Max", help="Vorname")
    parser.add_argument("--last-name", default="Mustermann", help="Nachname")
    parser.add_argument("--username", default="max.mustermann", help="Benutzername/Loginname")
    parser.add_argument("--active", choices=["true", "false"], default="true", help="Aktivstatus")
    parser.add_argument(
        "--role",
        action="append",
        default=[],
        help="WordPress-Rolle; mehrfach angebbar, z. B. --role subscriber --role member",
    )
    parser.add_argument(
        "--profile",
        action="append",
        default=[],
        help='Profilfeld im Format KEY=VALUE; mehrfach angebbar, z. B. --profile PHONE="+49 ..."',
    )

    return parser.parse_args()


def parse_profile_args(profile_args: list[str]) -> dict[str, str]:
    profile: dict[str, str] = {}
    for item in profile_args:
        if "=" not in item:
            raise ValueError(f"Ungültiges --profile-Argument '{item}'. Erwartet wird KEY=VALUE.")
        key, value = item.split("=", 1)
        key = key.strip()
        if not key:
            raise ValueError(f"Ungültiges --profile-Argument '{item}'. KEY darf nicht leer sein.")
        profile[key] = value
    return profile


def build_payload_from_args(args: argparse.Namespace) -> dict[str, Any]:
    roles = args.role if args.role else ["member"]
    profile = parse_profile_args(args.profile)

    payload = {
        "external_id": args.external_id,
        "email": args.email,
        "first_name": args.first_name,
        "last_name": args.last_name,
        "username": args.username,
        "active": args.active.lower() == "true",
        "roles": roles,
        "profile": profile,
    }
    return payload


def load_payload(path: str) -> dict[str, Any]:
    payload_path = Path(path)
    if not payload_path.is_file():
        raise FileNotFoundError(f"Payload-Datei nicht gefunden: {payload_path}")

    try:
        return json.loads(payload_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise ValueError(f"Ungültiges JSON in {payload_path}: {exc}") from exc



def send_request(url: str, token: str, payload: dict[str, Any], timeout: int, insecure: bool) -> tuple[int, str, dict[str, str]]:
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
    if insecure:
        context = ssl.create_default_context()
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE

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
        payload = load_payload(args.payload) if args.payload else build_payload_from_args(args)
    except Exception as exc:
        print(f"Fehler beim Erzeugen/Laden des Payloads: {exc}", file=sys.stderr)
        return 2

    print("=== Request ===")
    print(f"URL: {args.url}")
    print("Headers:")
    print("  Authorization: Bearer ********")
    print("  Content-Type: application/json")
    print("Body:")
    print(json.dumps(payload, ensure_ascii=False, indent=2))
    print()

    try:
        status, response_text, headers = send_request(
            url=args.url,
            token=args.token,
            payload=payload,
            timeout=args.timeout,
            insecure=args.insecure,
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
        if args.pretty:
            print(json.dumps(parsed, ensure_ascii=False, indent=2))
        else:
            print(json.dumps(parsed, ensure_ascii=False))
    else:
        print(response_text)

    if 200 <= status < 300:
        return 0
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
