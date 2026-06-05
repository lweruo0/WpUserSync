# WpUserSync (Admidio 5.0 kompatibel)

Dieses Plugin ist eine klassische `adm_plugins`-Variante für **Admidio 5.0.x** ohne Abhängigkeit vom neuen Plugin-Manager-Stack.

## Enthalten

- `index.php` – Infoseite mit Konfigurationsstatus
- `api/write_user.php` – JSON-Endpoint zum Anlegen/Aktualisieren
- `api/read_user.php` – JSON-Endpoint zum Auslesen von Profildaten
- `bootstrap-admidio.php` – Bootstrap für klassische Admidio-Strukturen
- `bootstrap-plugin.php` – lokaler PSR-4-Autoloader

## Installation

1. Ordner `WpUserSync` nach `adm_plugins/` kopieren.
2. Konfiguration in `adm_my_files/config.php` ergänzen (siehe Plugin-Infoseite in Admidio).
3. SHA-256-Hash des geheimen Tokens eintragen.

## Beispiel: Konfiguration in adm_my_files/config.php

```php
$plg_wpusersync_enabled = true;
$plg_wpusersync_require_https = true;
$plg_wpusersync_assign_default_roles = true;
$plg_wpusersync_api_token_hash = '...'; // hash('sha256', 'mein-token')
$plg_wpusersync_nonce_max_age = 300;
```

## Beispiel: Token-Hash

```php
<?php
echo hash('sha256', 'mein-langes-geheimes-token') . PHP_EOL;
```

## API-Authentifizierung

```http
POST /adm_plugins/WpUserSync/api/write_user.php
X-Api-Token: <token>
X-Api-Nonce: <unixzeit>.<hmac_sha256>
Content-Type: application/json
```
