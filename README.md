# WpUserSync (Admidio 5.0 kompatibel)

Dieses Plugin ist eine klassische `adm_plugins`-Variante für **Admidio 5.0.x** ohne Abhängigkeit vom neuen Plugin-Manager-Stack.

## Enthalten

- `index.php` – Infoseite mit Konfigurationsstatus
- `api/api.php` – RESTful JSON-API für Benutzer, Felder, Listen und Mitgliedschaften (via `.htaccess`)
- `api/write_user.php` – JSON-Endpoint zum Anlegen/Aktualisieren (Legacy)
- `api/read_user.php` – JSON-Endpoint zum Auslesen von Profildaten (Legacy)
- `bootstrap-admidio.php` – Bootstrap für klassische Admidio-Strukturen
- `bootstrap-plugin.php` – lokaler PSR-4-Autoloader

## Installation

1. Ordner `WpUserSync` nach `adm_plugins/` kopieren.
2. Konfiguration in `adm_my_files/config.php` ergänzen (siehe Plugin-Infoseite in Admidio).
3. Ein langes gemeinsames Secret (`$plg_wpusersync_api_secret`) setzen.


## Konfiguration .htaccess erweitern
```
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^adm_plugins/wpusersync/api/v1/(.*)$ /adm_plugins/wpusersync/api/api.php [QSA,L]
</IfModule>
```

## Beispiel: Konfiguration in adm_my_files/config.php

```php
$plg_wpusersync_enabled = true;
$plg_wpusersync_require_https = true;
$plg_wpusersync_assign_default_roles = true;
$plg_wpusersync_api_secret = 'mein-langes-zufaelliges-shared-secret';
$plg_wpusersync_nonce_max_age = 300;
```

## API-Authentifizierung

```http
POST /adm_plugins/WpUserSync/api/write_user.php
X-Api-Client-Id: <feste_client_id>
X-Api-Timestamp: <unixzeit>
X-Api-Nonce: <zufaelliger_nonce>
X-Api-Body-Sha256: <sha256_hex_des_raw_json>
X-Api-Signature: <hmac_sha256_hex>
Content-Type: application/json
```

Signatur-Basis (jeweils mit `\n` getrennt):

```text
METHOD
PATH
CLIENT_ID
TIMESTAMP
NONCE
BODY_SHA256
```

## REST-API Endpunkte (v1)

**URLs verwenden das Routing-Pattern via .htaccess:**
```
/adm_plugins/wpusersync/api/v1/...
```

**Alle Endpunkte erfordern HMAC-Request-Signing** (siehe Authentifizierung oben).

### Benutzer verwalten

- `GET /core/users` – Liste aller Benutzer (mit `?limit=50&offset=0`)
- `GET /core/users/{userId}` – Benutzerdetails auslesen
- `POST /core/users/{userId}` – Benutzer aktualisieren

### Benutzerdefinierte Felder

- `GET /core/users/{userId}/fields` – Alle Custom-Fields auslesen
- `GET /core/users/{userId}/fields/{name}` – Einzelnes Custom-Field auslesen
- `POST /core/users/{userId}/fields` – Custom-Field setzen (mit `{"name":"xxx","value":"yyy"}`)
- `POST /core/users/{userId}/fields/{name}` – Custom-Field byName setzen (mit `{"value":"yyy"}`)

### Listen

- `GET /core/users/{userId}/lists` – Listen, in denen Benutzer aktiv ist
- `GET /core/users/{userId}/lists/{listId}` – Listendetails (nur wenn Mitglied)

### Mitgliedschaften

- `GET /core/users/{userId}/memberships` – Alle Mitgliedschaften des Benutzers
- `GET /core/users/{userId}/memberships/{memId}` – Einzelne Mitgliedschaft auslesen
- `POST /core/users/{userId}/memberships/{memId}` – Mitgliedschaft aktualisieren (mit `{"beginDate":"...","endDate":"..."}`)
- `GET /core/users/{userId}/memberships/role/{roleId}` – Mitgliedschaften für spezifische Rolle
- `POST /core/users/{userId}/memberships/role/{roleId}` – Mitgliedschaft für Rolle erstellen
- `GET /core/users/{userId}/memberships/organization/{orgId}` – Mitgliedschaften für Org
- `POST /core/users/{userId}/memberships/organization/{orgId}` – Mitgliedschaft für Org erstellen

### Beispiel-Requests

**Benutzer auslesen:**
```bash
curl -X GET https://example.org/adm_plugins/wpusersync/api/v1/core/users/42 \
  -H "X-Api-Client-Id: wordpress-prod" \
  -H "X-Api-Timestamp: $(date +%s)" \
  -H "X-Api-Nonce: $(openssl rand -hex 16)" \
  -H "X-Api-Body-Sha256: $(echo -n '' | sha256sum | cut -d' ' -f1)" \
  -H "X-Api-Signature: <HMAC-SHA256>" \
  -H "Content-Type: application/json"
```

**Custom-Field setzen:**
```bash
PAYLOAD='{"value":"neuer-wert"}'
curl -X POST https://example.org/adm_plugins/wpusersync/api/v1/core/users/42/fields/mein_feld \
  -H "X-Api-Client-Id: wordpress-prod" \
  -H "X-Api-Timestamp: $(date +%s)" \
  -H "X-Api-Nonce: $(openssl rand -hex 16)" \
  -H "X-Api-Body-Sha256: $(echo -n "$PAYLOAD" | sha256sum | cut -d' ' -f1)" \
  -H "X-Api-Signature: <HMAC-SHA256>" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD"
```

