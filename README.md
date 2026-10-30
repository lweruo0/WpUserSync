# WpUserSync (Admidio 5.0 kompatibel)

Dieses Plugin ist eine klassische `adm_plugins`-Variante für **Admidio 5.0.x** ohne Abhängigkeit vom neuen Plugin-Manager-Stack.

## Enthalten

- `index.php` – Infoseite mit Konfigurationsstatus
- `api/api.php` – JSON-API für Benutzer, Felder, Rollen, Kategorien, Arbeitsdienste und Mitgliedsart-Wechsel (via `.htaccess`)
- `classes/Service/MembershipTypeService.php` – Mitgliedsart-Wechsel über `AdmHistory\MembershipApi`
- `classes/Service/ApiUser.php` – setzt den technischen API-Benutzer als angemeldeten Benutzer
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
// API-Benutzer für alle Änderungen (Pflicht für POST/DELETE, siehe unten)
$plg_wpusersync_api_user_uuid = '00000000-0000-0000-0000-000000000000';
// optional, Standard: adm_plugins/adm-history
// $plg_wpusersync_admhistory_dir = __DIR__ . '/../adm_plugins/adm-history';
```

## API-Benutzer

Die API hat keine Admidio-Anmeldung. Nach erfolgreicher Signaturprüfung setzt `api/api.php` deshalb den Benutzer aus `$plg_wpusersync_api_user_uuid` als angemeldeten Benutzer (`$gCurrentUser`). Admidio trägt ihn bei allen Änderungen aus WordPress im Änderungsprotokoll ein.

- Schreibende Anfragen (POST, DELETE) werden ohne gültigen API-Benutzer mit `500` und `code: api_user_not_configured` bzw. `api_user_invalid` abgelehnt.
- Lesende Anfragen (GET) laufen mit dem API-Benutzer, falls konfiguriert, sonst als Gast.
- Rechte des API-Benutzers prüft bisher nur der Mitgliedsart-Wechsel (über adm-history). Die übrigen Endpunkte schreiben weiterhin ohne Rechteprüfung.

Empfehlung: eigenen Admidio-Benutzer (z. B. „WordPress API“) ohne Login-Daten anlegen und ihm eine Rolle mit dem Recht „Rollen zuordnen“ für die betroffenen Rollen geben.

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

### Mitgliedsart wechseln (über Plugin adm-history)

Voraussetzung: Das Plugin „Mitglieder-Historie“ liegt in `adm_plugins/adm-history` (oder in `$plg_wpusersync_admhistory_dir`). Erlaubte Wechsel, Pflicht- und optionale Beitragsrollen stehen ausschließlich in `adm-history/mitgliedsarten.php` und werden von `AdmHistory\MembershipApi` geprüft. Ein Wechsel gilt immer ab dem 1. Januar, nur für das aktuelle Jahr und das Folgejahr.

Geändert wird im Namen des API-Benutzers (siehe „API-Benutzer“). Für den Wechsel braucht er das Recht, Mitglieder aller Mitgliedsart-, gemeinsamen und Beitragsrollen zuzuordnen.

- `GET /core/mitgliedsarten` – konfigurierte Mitgliedsarten mit Rollen, Pflicht-/optionalen Beitragsrollen und erlaubten Zielen
- `GET /core/users/{uuid}/mitgliedsart` – je Jahr (aktuell, Folgejahr): Mitgliedsart am 31.12., Zusatzbeiträge und `options` (bisherige Art plus erlaubte Ziele mit ihren optionalen Beitragsrollen) – geeignet, um in WordPress die Auswahl aufzubauen
- `POST /core/users/{uuid}/mitgliedsart` – Mitgliedsart und Zusatzbeiträge setzen:
  - `year` (Pflicht): aktuelles Jahr oder Folgejahr
  - `art` (Pflicht): Kürzel oder Name, z. B. `"A"` oder `"Aktiv"`; `""` = Kündigung
  - `fee` (optional): genau diese optionalen Beitragsrollen gelten danach im Jahr, `[]` entfernt alle. Fehlt `fee`, bleiben bisherige Zusatzbeiträge, soweit sie zur neuen Mitgliedsart gehören.

```json
{"year": 2027, "art": "A", "fee": ["Bootsbeitrag"]}
```

Antwort: `art`, `name`, `fees`, `operations` (Anzahl Datenbankänderungen, `0` = nichts zu tun) und der neue Stand `years` wie beim GET. Fachliche Fehler (nicht erlaubter Wechsel, unpassender Zusatzbeitrag, fehlende Rechte) antworten mit `422` und `code: membership_change_failed`.

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
