# Changelog

All notable changes to this package are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-28

### Added

- `mailgazelle` mail transport for Laravel Mail, Mailables, notifications, and queued mail, sending through `mailgazelle/php-sdk`.
- `MailGazelleServiceProvider`, which registers the mailer, binds `MailGazelle\Client`, and publishes `config/mailgazelle.php`.
- `MailGazelle` facade for the PHP SDK client.
- Config for the API token, API root, and timeouts. The token is also read from the mailer entry and from `config/services.php`.
- Support for Laravel 11, 12, and 13 on PHP 8.2 or newer.
