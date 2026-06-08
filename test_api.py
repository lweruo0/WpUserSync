#!/usr/bin/env python3
"""
pytest-Testsuite für WpUserSync API.

Konfiguration über Umgebungsvariablen:
    WPUSERSYNC_BASE_URL   Basis-URL zur API, z. B.
                          https://example.org/adm_plugins/WpUserSync/api
    WPUSERSYNC_SECRET     Gemeinsames HMAC-Secret
    WPUSERSYNC_CLIENT_ID  Client-ID (Standard: wordpress-prod)
    WPUSERSYNC_TIMEOUT    Request-Timeout in Sekunden (Standard: 20)

Nur Unit-Tests ausführen (kein Netzwerk):
    pytest test_api.py -k "TestSignatureHelpers"

Alle Tests ausführen (Netzwerk erforderlich):
    set WPUSERSYNC_BASE_URL=https://example.org/adm_plugins/WpUserSync/api
    set WPUSERSYNC_SECRET=mein-langes-zufaelliges-shared-secret
    pytest test_api.py -v
"""

from __future__ import annotations

import hashlib
import hmac
import json
import os
import secrets
import time
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urlparse
from urllib.request import Request, urlopen

import pytest


# ---------------------------------------------------------------------------
# HMAC-Signatur-Hilfsfunktionen
# ---------------------------------------------------------------------------

def build_signature_headers(
    method: str,
    url: str,
    client_id: str,
    shared_secret: str,
    body: bytes,
    *,
    timestamp: str | None = None,
    nonce: str | None = None,
) -> dict[str, str]:
    """Baut HMAC-Signatur-Header entsprechend der Canonical-String-Logik des Servers.

    Canonical String:
        METHOD\\nPATH\\nCLIENT_ID\\nTIMESTAMP\\nNONCE\\nBODY_SHA256
    """
    ts = timestamp if timestamp is not None else str(int(time.time()))
    nc = nonce if nonce is not None else secrets.token_hex(16)
    body_hash = hashlib.sha256(body).hexdigest()
    path = urlparse(url).path or "/"
    canonical = "\n".join([method.upper(), path, client_id, ts, nc, body_hash])
    signature = hmac.new(shared_secret.encode(), canonical.encode(), hashlib.sha256).hexdigest()
    return {
        "X-Api-Client-Id": client_id,
        "X-Api-Timestamp": ts,
        "X-Api-Nonce": nc,
        "X-Api-Body-Sha256": body_hash,
        "X-Api-Signature": signature,
    }


def send_request(
    url: str,
    body: bytes,
    extra_headers: dict[str, str] | None = None,
    timeout: int = 20,
) -> tuple[int, dict[str, Any]]:
    """Sendet einen POST-Request und gibt (HTTP-Status, geparste JSON-Antwort) zurück."""
    headers: dict[str, str] = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": "WpUserSync-TestClient/1.0",
    }
    if extra_headers:
        headers.update(extra_headers)

    request = Request(url=url, data=body, method="POST", headers=headers)
    try:
        with urlopen(request, timeout=timeout) as resp:
            return resp.status, json.loads(resp.read())
    except HTTPError as exc:
        raw = exc.read()
        try:
            data = json.loads(raw)
        except json.JSONDecodeError:
            data = {"raw": raw.decode("utf-8", errors="replace")}
        return exc.code, data
    except URLError as exc:
        pytest.skip(f"Server nicht erreichbar: {exc}")


def signed_request(
    url: str,
    payload: dict[str, Any],
    client_id: str,
    secret: str,
    timeout: int = 20,
    *,
    timestamp: str | None = None,
    nonce: str | None = None,
    override_headers: dict[str, str] | None = None,
) -> tuple[int, dict[str, Any]]:
    """Erstellt einen signierten POST-Request und sendet ihn ab."""
    body = json.dumps(payload, ensure_ascii=False).encode()
    sig_headers = build_signature_headers(
        "POST", url, client_id, secret, body,
        timestamp=timestamp, nonce=nonce,
    )
    if override_headers:
        sig_headers.update(override_headers)
    return send_request(url, body, extra_headers=sig_headers, timeout=timeout)


# ---------------------------------------------------------------------------
# Fixtures / Konfiguration
# ---------------------------------------------------------------------------

@pytest.fixture(scope="session")
def api_config() -> dict[str, Any]:
    """Liest API-Konfiguration aus Umgebungsvariablen.

    Integrationstests werden automatisch übersprungen, wenn
    WPUSERSYNC_BASE_URL oder WPUSERSYNC_SECRET nicht gesetzt sind.
    """
    base = os.environ.get("WPUSERSYNC_BASE_URL", "").rstrip("/")
    secret = os.environ.get("WPUSERSYNC_SECRET", "")
    client_id = os.environ.get("WPUSERSYNC_CLIENT_ID", "wordpress-prod")
    timeout = int(os.environ.get("WPUSERSYNC_TIMEOUT", "20"))

    if not base or not secret:
        pytest.skip(
            "Integrationstests übersprungen. "
            "Bitte WPUSERSYNC_BASE_URL und WPUSERSYNC_SECRET setzen."
        )

    return {
        "write_url": f"{base}/write_user.php",
        "read_url": f"{base}/read_user.php",
        "client_id": client_id,
        "secret": secret,
        "timeout": timeout,
    }


# ---------------------------------------------------------------------------
# Testdaten
# ---------------------------------------------------------------------------

VALID_WRITE_PAYLOAD: dict[str, Any] = {
    "active": True,
    "roles": [],
    "profile": {
        "EMAIL": "pytest.testuser@example.org",
        "FIRST_NAME": "PyTest",
        "LAST_NAME": "Runner",
        "BIRTHDAY": "1990-06-08",
        "GENDER": "M",
        "STREET": "Teststraße 1",
        "POSTCODE": "12345",
        "CITY": "Teststadt",
    },
}

VALID_READ_PAYLOAD: dict[str, Any] = {
    "profile": {
        "FIRST_NAME": "PyTest",
        "LAST_NAME": "Runner",
        "BIRTHDAY": "1990-06-08",
    },
}


# ---------------------------------------------------------------------------
# Unit-Tests: HMAC-Signatur-Logik (kein Netzwerk)
# ---------------------------------------------------------------------------

class TestSignatureHelpers:
    """Unit-Tests für die HMAC-Signatur-Hilfsfunktion."""

    URL = "https://example.org/adm_plugins/WpUserSync/api/write_user.php"
    SECRET = "test-secret-1234"
    CLIENT_ID = "wp-client"
    BODY = b'{"profile":{"EMAIL":"a@b.de"}}'

    def _headers(self, **kwargs: Any) -> dict[str, str]:
        return build_signature_headers(
            "POST", self.URL, self.CLIENT_ID, self.SECRET, self.BODY, **kwargs
        )

    def test_all_required_header_keys_present(self) -> None:
        headers = self._headers()
        for key in (
            "X-Api-Client-Id",
            "X-Api-Timestamp",
            "X-Api-Nonce",
            "X-Api-Body-Sha256",
            "X-Api-Signature",
        ):
            assert key in headers, f"Pflicht-Header {key!r} fehlt"

    def test_client_id_matches(self) -> None:
        assert self._headers()["X-Api-Client-Id"] == self.CLIENT_ID

    def test_body_hash_is_64_char_lowercase_hex(self) -> None:
        h = self._headers()["X-Api-Body-Sha256"]
        assert len(h) == 64
        assert all(c in "0123456789abcdef" for c in h)

    def test_body_hash_matches_sha256_of_body(self) -> None:
        expected = hashlib.sha256(self.BODY).hexdigest()
        assert self._headers()["X-Api-Body-Sha256"] == expected

    def test_signature_is_64_char_lowercase_hex(self) -> None:
        sig = self._headers()["X-Api-Signature"]
        assert len(sig) == 64
        assert all(c in "0123456789abcdef" for c in sig)

    def test_signature_changes_when_body_changes(self) -> None:
        fixed = dict(timestamp="1000000000", nonce="aabbcc1122334455aabb")
        h1 = build_signature_headers("POST", self.URL, self.CLIENT_ID, self.SECRET, b'{"a":1}', **fixed)
        h2 = build_signature_headers("POST", self.URL, self.CLIENT_ID, self.SECRET, b'{"a":2}', **fixed)
        assert h1["X-Api-Signature"] != h2["X-Api-Signature"]

    def test_signature_changes_when_path_changes(self) -> None:
        fixed = dict(timestamp="1000000000", nonce="aabbcc1122334455aabb")
        url_write = "https://example.org/api/write_user.php"
        url_read = "https://example.org/api/read_user.php"
        h1 = build_signature_headers("POST", url_write, self.CLIENT_ID, self.SECRET, self.BODY, **fixed)
        h2 = build_signature_headers("POST", url_read, self.CLIENT_ID, self.SECRET, self.BODY, **fixed)
        assert h1["X-Api-Signature"] != h2["X-Api-Signature"]

    def test_signature_changes_when_secret_changes(self) -> None:
        fixed = dict(timestamp="1000000000", nonce="aabbcc1122334455aabb")
        h1 = build_signature_headers("POST", self.URL, self.CLIENT_ID, "secret-a", self.BODY, **fixed)
        h2 = build_signature_headers("POST", self.URL, self.CLIENT_ID, "secret-b", self.BODY, **fixed)
        assert h1["X-Api-Signature"] != h2["X-Api-Signature"]

    def test_signature_changes_when_client_id_changes(self) -> None:
        fixed = dict(timestamp="1000000000", nonce="aabbcc1122334455aabb")
        h1 = build_signature_headers("POST", self.URL, "client-a", self.SECRET, self.BODY, **fixed)
        h2 = build_signature_headers("POST", self.URL, "client-b", self.SECRET, self.BODY, **fixed)
        assert h1["X-Api-Signature"] != h2["X-Api-Signature"]

    def test_signature_deterministic_with_fixed_inputs(self) -> None:
        kwargs = dict(timestamp="1717833600", nonce="deadbeef12345678abcd")
        h1 = self._headers(**kwargs)
        h2 = self._headers(**kwargs)
        assert h1["X-Api-Signature"] == h2["X-Api-Signature"]

    def test_canonical_string_structure(self) -> None:
        """Verifiziert den Canonical String gegen eine manuelle Berechnung."""
        ts, nc = "1717833600", "deadbeef12345678abcd"
        body = b'{"hello":"world"}'
        body_hash = hashlib.sha256(body).hexdigest()
        path = "/adm_plugins/WpUserSync/api/write_user.php"
        canonical = "\n".join(["POST", path, self.CLIENT_ID, ts, nc, body_hash])
        expected_sig = hmac.new(self.SECRET.encode(), canonical.encode(), hashlib.sha256).hexdigest()

        headers = build_signature_headers(
            "POST", self.URL, self.CLIENT_ID, self.SECRET, body, timestamp=ts, nonce=nc
        )
        assert headers["X-Api-Signature"] == expected_sig
        assert headers["X-Api-Body-Sha256"] == body_hash

    def test_nonce_is_random_each_call(self) -> None:
        nonces = {self._headers()["X-Api-Nonce"] for _ in range(10)}
        assert len(nonces) > 1, "Nonce sollte bei jedem Aufruf verschieden sein"

    def test_timestamp_is_recent(self) -> None:
        before = int(time.time()) - 2
        headers = self._headers()
        after = int(time.time()) + 2
        ts = int(headers["X-Api-Timestamp"])
        assert before <= ts <= after, f"Timestamp {ts} liegt außerhalb des erwarteten Bereichs"

    def test_method_is_uppercased_in_canonical(self) -> None:
        fixed = dict(timestamp="1000000000", nonce="aabbcc1122334455aabb")
        h_lower = build_signature_headers("post", self.URL, self.CLIENT_ID, self.SECRET, self.BODY, **fixed)
        h_upper = build_signature_headers("POST", self.URL, self.CLIENT_ID, self.SECRET, self.BODY, **fixed)
        assert h_lower["X-Api-Signature"] == h_upper["X-Api-Signature"]

    def test_empty_body_produces_valid_headers(self) -> None:
        headers = build_signature_headers("POST", self.URL, self.CLIENT_ID, self.SECRET, b"")
        expected_hash = hashlib.sha256(b"").hexdigest()
        assert headers["X-Api-Body-Sha256"] == expected_hash


# ---------------------------------------------------------------------------
# Integrationstests: write_user.php
# ---------------------------------------------------------------------------

class TestWriteUser:
    """Integrationstests für write_user.php."""

    def test_write_valid_user_returns_200_or_201(self, api_config: dict[str, Any]) -> None:
        status, data = signed_request(
            api_config["write_url"],
            VALID_WRITE_PAYLOAD,
            api_config["client_id"],
            api_config["secret"],
            api_config["timeout"],
        )
        assert status in (200, 201), f"Erwartet 200/201, erhalten {status}: {data}"
        assert data.get("status") in ("created", "updated"), f"Unerwarteter Status: {data}"

    def test_write_missing_email_returns_422(self, api_config: dict[str, Any]) -> None:
        payload = {
            **VALID_WRITE_PAYLOAD,
            "profile": {k: v for k, v in VALID_WRITE_PAYLOAD["profile"].items() if k != "EMAIL"},
        }
        status, data = signed_request(
            api_config["write_url"], payload,
            api_config["client_id"], api_config["secret"], api_config["timeout"],
        )
        assert status == 422
        assert data.get("code") == "validation_failed"

    def test_write_invalid_email_returns_422(self, api_config: dict[str, Any]) -> None:
        payload = {**VALID_WRITE_PAYLOAD, "profile": {**VALID_WRITE_PAYLOAD["profile"], "EMAIL": "kein-email"}}
        status, data = signed_request(
            api_config["write_url"], payload,
            api_config["client_id"], api_config["secret"], api_config["timeout"],
        )
        assert status == 422
        assert data.get("code") == "validation_failed"

    def test_write_invalid_gender_returns_422(self, api_config: dict[str, Any]) -> None:
        payload = {**VALID_WRITE_PAYLOAD, "profile": {**VALID_WRITE_PAYLOAD["profile"], "GENDER": "X"}}
        status, data = signed_request(
            api_config["write_url"], payload,
            api_config["client_id"], api_config["secret"], api_config["timeout"],
        )
        assert status == 422
        assert data.get("code") == "validation_failed"

    @pytest.mark.parametrize("gender", ["M", "MALE", "W", "F", "FEMALE"])
    def test_write_valid_gender_variants(self, api_config: dict[str, Any], gender: str) -> None:
        payload = {**VALID_WRITE_PAYLOAD, "profile": {**VALID_WRITE_PAYLOAD["profile"], "GENDER": gender}}
        status, data = signed_request(
            api_config["write_url"], payload,
            api_config["client_id"], api_config["secret"], api_config["timeout"],
        )
        assert status in (200, 201), f"Ungültiger Status {status} für GENDER={gender!r}: {data}"

    @pytest.mark.parametrize("missing_field", ["FIRST_NAME", "LAST_NAME", "BIRTHDAY", "STREET", "POSTCODE", "CITY"])
    def test_write_missing_required_field_returns_422(
        self, api_config: dict[str, Any], missing_field: str
    ) -> None:
        payload = {
            **VALID_WRITE_PAYLOAD,
            "profile": {k: v for k, v in VALID_WRITE_PAYLOAD["profile"].items() if k != missing_field},
        }
        status, data = signed_request(
            api_config["write_url"], payload,
            api_config["client_id"], api_config["secret"], api_config["timeout"],
        )
        assert status == 422, f"Fehlendes {missing_field!r} hätte 422 ergeben sollen, war {status}"

    def test_write_empty_body_returns_422(self, api_config: dict[str, Any]) -> None:
        body = b"{}"
        sig_headers = build_signature_headers(
            "POST", api_config["write_url"],
            api_config["client_id"], api_config["secret"], body,
        )
        status, data = send_request(
            api_config["write_url"], body,
            extra_headers=sig_headers, timeout=api_config["timeout"],
        )
        assert status == 422


# ---------------------------------------------------------------------------
# Integrationstests: read_user.php
# ---------------------------------------------------------------------------

class TestReadUser:
    """Integrationstests für read_user.php."""

    @pytest.fixture(autouse=True)
    def ensure_test_user_exists(self, api_config: dict[str, Any]) -> None:
        """Schreibt den Testbenutzer, bevor Lesetests ausgeführt werden."""
        signed_request(
            api_config["write_url"], VALID_WRITE_PAYLOAD,
            api_config["client_id"], api_config["secret"], api_config["timeout"],
        )

    def test_read_existing_user_returns_200(self, api_config: dict[str, Any]) -> None:
        status, data = signed_request(
            api_config["read_url"], VALID_READ_PAYLOAD,
            api_config["client_id"], api_config["secret"], api_config["timeout"],
        )
        assert status == 200, f"Erwartet 200, erhalten {status}: {data}"
        assert data.get("status") == "ok"
        assert "profile" in data
        assert "user_id" in data

    def test_read_returns_profile_fields(self, api_config: dict[str, Any]) -> None:
        status, data = signed_request(
            api_config["read_url"], VALID_READ_PAYLOAD,
            api_config["client_id"], api_config["secret"], api_config["timeout"],
        )
        assert status == 200
        profile = data.get("profile", {})
        assert isinstance(profile, dict)
        assert len(profile) > 0, "Profil sollte mindestens ein Feld enthalten"

    def test_read_nonexistent_user_returns_404(self, api_config: dict[str, Any]) -> None:
        payload = {"profile": {"FIRST_NAME": "Xhxhxhx", "LAST_NAME": "Yhyhyhy", "BIRTHDAY": "1800-01-01"}}
        status, data = signed_request(
            api_config["read_url"], payload,
            api_config["client_id"], api_config["secret"], api_config["timeout"],
        )
        assert status == 404
        assert data.get("code") == "user_not_found"

    @pytest.mark.parametrize("missing_field", ["FIRST_NAME", "LAST_NAME", "BIRTHDAY"])
    def test_read_missing_required_field_returns_422(
        self, api_config: dict[str, Any], missing_field: str
    ) -> None:
        payload = {
            "profile": {k: v for k, v in VALID_READ_PAYLOAD["profile"].items() if k != missing_field}
        }
        status, data = signed_request(
            api_config["read_url"], payload,
            api_config["client_id"], api_config["secret"], api_config["timeout"],
        )
        assert status == 422, f"Fehlendes {missing_field!r} hätte 422 ergeben sollen, war {status}"

    def test_read_empty_profile_returns_422(self, api_config: dict[str, Any]) -> None:
        body = json.dumps({"profile": {}}, ensure_ascii=False).encode()
        sig_headers = build_signature_headers(
            "POST", api_config["read_url"],
            api_config["client_id"], api_config["secret"], body,
        )
        status, data = send_request(
            api_config["read_url"], body,
            extra_headers=sig_headers, timeout=api_config["timeout"],
        )
        assert status == 422


# ---------------------------------------------------------------------------
# Integrationstests: Authentifizierung & Sicherheit
# ---------------------------------------------------------------------------

class TestAuthentication:
    """Integrationstests für HMAC-Authentifizierung und Nonce-Validierung."""

    def test_wrong_secret_returns_401(self, api_config: dict[str, Any]) -> None:
        status, data = signed_request(
            api_config["write_url"], VALID_WRITE_PAYLOAD,
            api_config["client_id"], "falsches-secret-xyz",
            api_config["timeout"],
        )
        assert status == 401
        assert data.get("code") == "invalid_signature"

    def test_missing_signature_header_returns_401(self, api_config: dict[str, Any]) -> None:
        body = json.dumps(VALID_WRITE_PAYLOAD, ensure_ascii=False).encode()
        sig_headers = build_signature_headers(
            "POST", api_config["write_url"],
            api_config["client_id"], api_config["secret"], body,
        )
        del sig_headers["X-Api-Signature"]
        status, data = send_request(
            api_config["write_url"], body,
            extra_headers=sig_headers, timeout=api_config["timeout"],
        )
        assert status == 401
        assert data.get("code") == "missing_signature"

    def test_missing_client_id_returns_401(self, api_config: dict[str, Any]) -> None:
        body = json.dumps(VALID_WRITE_PAYLOAD, ensure_ascii=False).encode()
        sig_headers = build_signature_headers(
            "POST", api_config["write_url"],
            api_config["client_id"], api_config["secret"], body,
        )
        del sig_headers["X-Api-Client-Id"]
        status, data = send_request(
            api_config["write_url"], body,
            extra_headers=sig_headers, timeout=api_config["timeout"],
        )
        assert status == 401
        assert data.get("code") == "missing_client_id"

    def test_missing_timestamp_returns_401(self, api_config: dict[str, Any]) -> None:
        body = json.dumps(VALID_WRITE_PAYLOAD, ensure_ascii=False).encode()
        sig_headers = build_signature_headers(
            "POST", api_config["write_url"],
            api_config["client_id"], api_config["secret"], body,
        )
        del sig_headers["X-Api-Timestamp"]
        status, data = send_request(
            api_config["write_url"], body,
            extra_headers=sig_headers, timeout=api_config["timeout"],
        )
        assert status == 401
        assert data.get("code") == "missing_timestamp"

    def test_missing_nonce_returns_401(self, api_config: dict[str, Any]) -> None:
        body = json.dumps(VALID_WRITE_PAYLOAD, ensure_ascii=False).encode()
        sig_headers = build_signature_headers(
            "POST", api_config["write_url"],
            api_config["client_id"], api_config["secret"], body,
        )
        del sig_headers["X-Api-Nonce"]
        status, data = send_request(
            api_config["write_url"], body,
            extra_headers=sig_headers, timeout=api_config["timeout"],
        )
        assert status == 401
        assert data.get("code") == "missing_nonce"

    def test_expired_timestamp_returns_401(self, api_config: dict[str, Any]) -> None:
        old_ts = str(int(time.time()) - 3600)  # 1 Stunde alt
        status, data = signed_request(
            api_config["write_url"], VALID_WRITE_PAYLOAD,
            api_config["client_id"], api_config["secret"],
            api_config["timeout"],
            timestamp=old_ts,
        )
        assert status == 401
        assert data.get("code") == "nonce_expired"

    def test_non_numeric_timestamp_returns_401(self, api_config: dict[str, Any]) -> None:
        status, data = signed_request(
            api_config["write_url"], VALID_WRITE_PAYLOAD,
            api_config["client_id"], api_config["secret"],
            api_config["timeout"],
            timestamp="nicht-numerisch",
        )
        assert status == 401

    def test_replayed_nonce_returns_401(self, api_config: dict[str, Any]) -> None:
        fixed_nonce = secrets.token_hex(16)
        status1, _ = signed_request(
            api_config["write_url"], VALID_WRITE_PAYLOAD,
            api_config["client_id"], api_config["secret"],
            api_config["timeout"],
            nonce=fixed_nonce,
        )
        assert status1 in (200, 201), f"Erster Request sollte erfolgreich sein, war {status1}"

        status2, data2 = signed_request(
            api_config["write_url"], VALID_WRITE_PAYLOAD,
            api_config["client_id"], api_config["secret"],
            api_config["timeout"],
            nonce=fixed_nonce,
        )
        assert status2 == 401
        assert data2.get("code") == "replayed_nonce"

    def test_body_tampered_after_signing_returns_401(self, api_config: dict[str, Any]) -> None:
        """Signatur ist korrekt für Original-Body, aber anderer Body wird gesendet."""
        original_body = json.dumps(VALID_WRITE_PAYLOAD, ensure_ascii=False).encode()
        sig_headers = build_signature_headers(
            "POST", api_config["write_url"],
            api_config["client_id"], api_config["secret"], original_body,
        )
        tampered_body = original_body + b" "  # Body weicht von signiertem ab
        status, data = send_request(
            api_config["write_url"], tampered_body,
            extra_headers=sig_headers, timeout=api_config["timeout"],
        )
        assert status == 401
        assert data.get("code") == "invalid_body_hash"

    def test_forged_body_hash_header_returns_401(self, api_config: dict[str, Any]) -> None:
        """X-Api-Body-Sha256 auf gefälschten Wert gesetzt – Signatur passt nicht mehr."""
        body = json.dumps(VALID_WRITE_PAYLOAD, ensure_ascii=False).encode()
        sig_headers = build_signature_headers(
            "POST", api_config["write_url"],
            api_config["client_id"], api_config["secret"], body,
        )
        sig_headers["X-Api-Body-Sha256"] = "a" * 64
        status, data = send_request(
            api_config["write_url"], body,
            extra_headers=sig_headers, timeout=api_config["timeout"],
        )
        assert status == 401

    def test_invalid_nonce_format_too_short_returns_401(self, api_config: dict[str, Any]) -> None:
        status, data = signed_request(
            api_config["write_url"], VALID_WRITE_PAYLOAD,
            api_config["client_id"], api_config["secret"],
            api_config["timeout"],
            nonce="kurz",  # Muster erfordert 16-128 Zeichen
        )
        assert status == 401

    def test_invalid_client_id_format_returns_401(self, api_config: dict[str, Any]) -> None:
        status, data = signed_request(
            api_config["write_url"], VALID_WRITE_PAYLOAD,
            "invalid client id!",  # Leerzeichen und Sonderzeichen nicht erlaubt
            api_config["secret"],
            api_config["timeout"],
        )
        assert status == 401
