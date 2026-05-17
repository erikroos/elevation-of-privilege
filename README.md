# Elevation of Privilege — PHP/MySQL implementatie

Een zelf te hosten implementatie van het serious card game **Elevation of Privilege** (Adam Shostack / Microsoft) en **LINDDUN GO** (DistriNet, KU Leuven). Bedoeld voor onderwijs (bv. eerstejaars cybersecurity / privacy).

## Eigenschappen

- Plain PHP 8.1+, MySQL/MariaDB, geen Composer/framework nodig.
- Draait op gewone shared hosting (getest met Antagonist).
- Facilitator-modus: docent host, studenten joinen via game-code op eigen device.
- Keuze tussen **STRIDE** (Elevation of Privilege) en **LINDDUN** (LINDDUN GO) deck.
- Diagram-upload (PNG/JPG/SVG/PDF) van het te modelleren systeem.
- Threat-log met export (CSV + Markdown).
- UI in het Nederlands; kaartteksten in het Engels (originele bronnen).

## Installatie (shared hosting)

1. Maak een MySQL-database aan in het control panel.
2. Upload de hele `eop/` directory via FTP/SFTP naar de map _boven_ public_html. Wijs de webroot van het (sub)domein naar `eop/public/`.
3. Kopieer `config/config.example.php` naar `config/config.php` en vul DB-gegevens + `APP_SECRET` in.
4. Import het schema: voer `sql/schema.sql` uit via phpMyAdmin.
5. Open in de browser: `https://jouwdomein.nl/setup.php?token=<APP_SECRET>` — dit seedt de kaarten. Verwijder daarna `setup.php`.
6. Klaar — open `/` en maak een spel aan.

## Lokale ontwikkeling

```bash
cd eop
# Workers > 1 is nodig zodat de long-poll endpoint geen andere requests blokkeert
PHP_CLI_SERVER_WORKERS=4 php -d upload_max_filesize=15M -d post_max_size=15M \
  -S 127.0.0.1:8000 -t public
```

Op de gehoste omgeving (PHP-FPM) zijn er standaard meerdere workers, dus daar is niets
extra nodig.

## Licentie & attributie

Dit project staat onder **CC BY 4.0** (zie `LICENSE`).
Zie `ATTRIBUTION.md` voor de bronvermelding van de kaartdecks.
