# WpUserSync (Admidio 5.0 kompatibel)

Dieses Plugin ist eine klassische `adm_plugins`-Variante für **Admidio 5.0.x** ohne Abhängigkeit vom neuen Plugin-Manager-Stack. Der Plugin-Manager ist als eigenes Feature mit **Milestone v5.1** angelegt; gleichzeitig existieren in Admidio-Quellen bereits vorbereitende Komponenten wie `PluginAbstract.php`. Deshalb ist für produktive **5.0.x**-Installationen der klassische Plugin-Stil die sicherere Wahl.

## Enthalten

- `index.php` – einfache Infoseite
- `api/users.php` – JSON-Endpoint für POST-Requests
- `bootstrap-admidio.php` – robuster Bootstrap für klassische Admidio-Strukturen
- `bootstrap-plugin.php` – lokaler PSR-4-Autoloader für dieses Plugin
- `config_sample.php` – Beispielkonfiguration, bitte in `config.php` kopieren

## Installation

1. Ordner `WpUserSync` nach `adm_plugins/` kopieren.
2. `config_sample.php` nach `config.php` kopieren.
3. In Admidio ein Profilfeld `WP_USER_ID` (interner Name) anlegen.
4. SHA-256-Hash des geheimen Tokens in `config.php` eintragen.

## Beispiel: Token-Hash

```php
<?php
echo hash('sha256', 'mein-langes-geheimes-token') . PHP_EOL;
```

## Testrequest

```http
POST /adm_plugins/WpUserSync/api/users.php
Authorization: Bearer <token>
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
