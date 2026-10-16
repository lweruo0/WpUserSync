# WpUserSync (Admidio 5.0 kompatibel)

Dieses Plugin ist eine klassische `adm_plugins`-Variante für **Admidio 5.0.x** ohne Abhängigkeit vom neuen Plugin-Manager-Stack.

## Enthalten

- `index.php` – Infoseite mit Konfigurationsstatus
- `api/api.php` – JSON-API für Benutzer, Felder, Rollen, Kategorien und Arbeitsdienste (via `.htaccess`)
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
GET /adm_plugins/wpusersync/api/v1/core/users/{uuid}
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

### Profilfeld-Allowlist

Die Allowlist wird auf der Administratorseite `/adm_plugins/wpusersync/index.php` über getrennte Checkboxen für Lese- und Schreibzugriff gepflegt. Beim Speichern legt das Plugin `adm_my_files/wpusersync-profile-fields.json` an beziehungsweise aktualisiert diese Datei. Der PHP-Prozess muss Schreibrechte auf `adm_my_files` haben.

Eine fehlende Allowlist-Datei sperrt Profilfeld-Lese- und Schreibzugriffe standardmäßig (fail-closed). Für `POST /core/users/new` müssen `FIRST_NAME`, `LAST_NAME` und `BIRTHDAY` in der Schreib-Allowlist aktiviert sein. Benutzerlisten und Suchergebnisse geben diese Profilfelder nur zurück, wenn sie jeweils in der Lese-Allowlist stehen.

### Benutzer und Stammdaten

- `GET /core/users` – Liste aller Benutzer (mit `?limit=50&offset=0`)
- `GET /core/users/{uuid}` – Benutzer anhand seiner UUID auslesen
- `GET /core/search?firstName=...&lastName=...&birthday=...` – Benutzer anhand Name und Geburtstag suchen
- `POST /core/users/new` – Benutzer anlegen (erfordert `firstName`, `lastName` und `birthday`)
- `GET /core/roles` – Rollen auflisten
- `GET /core/categories` – Kategorien auflisten
- `GET /core/categories/{type}` – Kategorien nach Typ auflisten

### Benutzerdefinierte Felder

- `GET /core/users/{uuid}/fields` – Profilfelder des Benutzers auslesen
- `GET /core/users/{uuid}/fields/{name}` – Einzelnes Profilfeld auslesen
- `POST /core/users/{uuid}/fields` – Profilfelder setzen (mit `{"data":{"FELDNAME":"Wert"}}`)
- `POST /core/users/{uuid}/fields/{name}` – Einzelnes Profilfeld setzen (mit `{"data":"Wert"}`)

### Mitgliedschaften und Arbeitsdienste

- `GET /core/users/{uuid}/memberships` – Mitgliedschaften des Benutzers auslesen
- `GET /core/users/{uuid}/memberships/{year}` – Mitgliedschaften für ein Jahr auslesen
- `POST /core/users/{uuid}/memberships` – Rollenmitgliedschaften zuweisen (mit `{"roles":{"Rollenname":{"start_date":"YYYY-MM-DD","end_date":"YYYY-MM-DD"}}}`)
- `POST /core/users/{uuid}/mitgliedschaftbfv` – BFV-Mitgliedschaft und Beitragsrollen verwalten
- `GET /core/users/{uuid}/arbeitsdienst` – Arbeitsdienste auslesen
- `GET /core/users/{uuid}/arbeitsdienst/{year}` – Arbeitsdienste für ein Jahr auslesen
- `POST /core/users/{uuid}/arbeitsdienst/{year}` – Arbeitsdienst erfassen oder aktualisieren
- `DELETE /core/users/{uuid}/arbeitsdienst/{id}` – Arbeitsdienst löschen

Schreibzugriffe verwenden JSON im Request-Body. Der Router unterstützt nur die oben aufgeführten HTTP-Methoden und Routen; nicht aufgeführte Routen antworten mit `404`.

### Beispiel-Requests

**Benutzer auslesen:**
```bash
curl -X GET https://example.org/adm_plugins/wpusersync/api/v1/core/users/00000000-0000-0000-0000-000000000000 \
  -H "X-Api-Client-Id: wordpress-prod" \
  -H "X-Api-Timestamp: $(date +%s)" \
  -H "X-Api-Nonce: $(openssl rand -hex 16)" \
  -H "X-Api-Body-Sha256: $(echo -n '' | sha256sum | cut -d' ' -f1)" \
  -H "X-Api-Signature: <HMAC-SHA256>" \
  -H "Content-Type: application/json"
```

**Custom-Field setzen:**
```bash
PAYLOAD='{"data":"neuer-wert"}'
curl -X POST https://example.org/adm_plugins/wpusersync/api/v1/core/users/00000000-0000-0000-0000-000000000000/fields/mein_feld \
  -H "X-Api-Client-Id: wordpress-prod" \
  -H "X-Api-Timestamp: $(date +%s)" \
  -H "X-Api-Nonce: $(openssl rand -hex 16)" \
  -H "X-Api-Body-Sha256: $(echo -n "$PAYLOAD" | sha256sum | cut -d' ' -f1)" \
  -H "X-Api-Signature: <HMAC-SHA256>" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD"
```
