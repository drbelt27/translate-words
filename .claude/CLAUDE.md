# Istruzioni progetto

## Scopo
Fork (drbelt27/translate-words, upstream CoolPluginsTeam/translate-words) del plugin WordPress **Linguator AI** (derivato da Polylang): multilingua con traduzione AI. Lavoro in corso: identita condivisa dei prodotti WooCommerce (branch `feat/woocommerce-shared-product-identity-phase-1`).

## Architettura e stack
- Entry point: `translate-wp-words.php` (v2.2.0, text domain `translate-words`, WP >= 6.8, PHP >= 7.2).
- PHP con autoload PSR-4 (`composer.json`): `admin/`, `frontend/`, `includes/`, `modules/`, `integrations/`, `install/`. Opzioni in `includes/options/business/`.
- Frontend admin: webpack + `@wordpress/scripts` + Tailwind (`package.json`).
- `vendor/` contiene 10 file autoloader **tracciati**: non lanciare `composer install`/`update` nel repo (li riscrive).

## Regole di modifica
- Modifica minima corretta, niente refactoring collaterali, stile e API esistenti.
- Nuova logica non banale: un test in `tests/` (nome `test-*.php`).
- Sicurezza WordPress e stile: vedi `.claude/rules/` (security, code-style, code-review). Le regole citano un "AI Form Builder"/`Cool_Form_Builder`: sono generiche, applicare lo spirito, non i nomi.
- Fine riga LF.

## Verifiche
- Unit test (verificato 2026-11-13: 164 test, 2 saltati, 0 falliti): `phpunit --configuration phpunit.xml.dist`. La suite usa stub, WordPress/WooCommerce non sono caricati; `LMAT_TEST_WITHOUT_WOOCOMMERCE=1` simula WooCommerce assente.
- Lint/standard: `composer phpcs` (WordPress standard) — non eseguito.
- Build JS: `npm run build` — non eseguito. `npm test` non esiste.
- E2E: non applicabile (nessuna suite).
- CI: `.github/` e in deny di lettura, workflow non verificati.

## Ambiente locale (Windows)
PHP 8.2 e Composer installati fuori dal repo; PHPUnit/WPCS sono globali in `%APPDATA%\Composer\vendor\`. Dopo l'installazione serve una nuova shell per avere `php` nel PATH; Composer si lancia con `php composer.phar` dalla cartella di PHP (il `.bat` rompe i caret `^`).

## Sicurezza e deployment
- Nessuna operazione distruttiva, push, release o produzione senza autorizzazione.
- Nessuna chiave API nel codice: le chiavi dei provider AI restano server-side.
- Prima di release: code review del diff + audit di sicurezza WordPress (non ancora eseguito: NON VERIFICATO).

## Second Brain
- Bank Hindsight del progetto: `linguator-translate-words`. Regole trasversali: bank `claude-code-setup`. Usare sempre `bank_id` esplicito.
- A inizio sessione recuperare lo stato dal bank progetto; registrare decisioni, fix e test verificati; nessun segreto.
