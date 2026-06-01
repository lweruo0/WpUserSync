# WpUserSync

Admidio-5-Plugin-Grundgerüst für eine JSON-API, über die WordPress Benutzer in Admidio anlegt oder aktualisiert.

## Funktionsumfang

- JSON-Endpoint `POST /api/users.php`
- Bearer-Token-Authentifizierung mit SHA-256-Hash in der Plugin-Konfiguration
- optionale IP-Allowlist
- HTTPS-Pflicht
- Upsert per externem Profilfeld (z. B. `WP_USER_ID`) mit Fallback auf E-Mail
- Mapping von WordPress-Rollen auf Admidio-Rollen

## Erwarteter Request

```http
POST /plugins/WpUserSync/api/users.php HTTP/1.1
Authorization: Bearer <dein-token>
Content-Type: application/json
```

```json
{
  "external_id": "wp:123",
  "email": "max.mustermann@example.org",
  "first_name": "Max",
  "last_name": "Mustermann",
  "username": "max.mustermann",
  "roles": ["member"],
  "profile": {
    "PHONE": "+49 123 456789",
    "CITY": "Ehingen"
  }
}
```

## Token-Hash erzeugen

```php
<?php
$token = 'hier-ein-lang-zufaelliges-token';
echo hash('sha256', $token) . PHP_EOL;
```

## Hinweise

- Vor der Nutzung in Admidio ein Profilfeld mit internem Namen `WP_USER_ID` anlegen.
- Die Bootstrap-Pfade `../../system/common.php` bzw. `../../../system/common.php` basieren auf dem Admidio-5-Beispiel-Plugin. Falls deine Installation eine abweichende Struktur hat, die Pfade entsprechend anpassen.
- Rollen werden aktuell additiv gesetzt; ein Entzug nicht mehr gemappter Rollen ist in diesem Grundgerüst noch nicht implementiert.
