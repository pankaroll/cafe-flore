# Cafe Flore

Aplikacja zadań dla kawiarni: logowanie PIN-em, konta pracowników, role właściciela i pracownika, checklisty otwarcia i zamknięcia, zadania cykliczne, zgłaszanie problemów i historia wykonań.

React + TypeScript, PHP 8.2+ (zalecane 8.4), MySQL 8.0+. Hosting docelowy: OVHcloud współdzielony. Na serwerze nie potrzeba Node.js, Composera ani VPS.

## Co jest w repozytorium

- `frontend/` — źródła interfejsu i zależności npm.
- `public/` — gotowa aplikacja, instalator i API; jedyny katalog dostępny pod domeną.
- `private/src/` — backend PHP.
- `private/schema.sql` — schemat pustej bazy, bez danych użytkowników.
- `private/seed-tasks.json` — 58 szablonów zadań: 12 otwarcia, 37 zamknięcia i 9 tygodniowych.
- `tests/` — testy API, uprawnień, cykli i logowania.
- `scripts/prepare-install.mjs` — generator jednorazowego kodu instalacyjnego.

Repozytorium nie zawiera konfiguracji produkcyjnej, haseł, klucza PIN, kopii bazy ani kont pracowników.

## Nowa instalacja na OVH

1. Sklonuj repozytorium na komputer. Zainstaluj Node.js >=22.13.0, jeżeli chcesz budować interfejs lub wygenerować kod instalacyjny.
2. W katalogu repozytorium wykonaj `node scripts/prepare-install.mjs`. Kod zostanie zapisany do ignorowanego przez Git pliku `private/install-key.txt`.
3. Wgraj katalogi `public` i `private` obok siebie, np. do folderu `cafe-flore` na hostingu. Prześlij również pliki `.htaccess`.
4. Ustaw katalog domeny na `cafe-flore/public`. Włącz PHP 8.4 i certyfikat SSL dla dokładnego adresu aplikacji.
5. Utwórz bazę MySQL. PHP wymaga rozszerzeń `pdo_mysql`, `openssl`, `mbstring`.
6. Otwórz `https://twoja-domena/setup.php`. Podaj kod instalacyjny, dane MySQL i pełny docelowy adres HTTPS. Wybierz nową instalację oraz ustaw PIN właściciela (6–8 cyfr).
7. Po instalacji `setup.php` wyłączy się automatycznie. Konta pracowników utworzysz w aplikacji.

Dane istniejącej instalacji są w MySQL oraz w prywatnej konfiguracji. Samo umieszczenie kodu na GitHubie nie przenosi tych danych i nie uruchamia nowej instalacji.

## Aktualizacja istniejącej instalacji

Zachowaj istniejący `private/config.php` oraz bazę. Wgraj zmieniony kod PHP i gotowe pliki interfejsu. Nie uruchamiaj ponownie instalatora. `config.php` zawiera dane MySQL i sekret HMAC potrzebny do zachowania PIN-ów.

Nie wgrywaj całego katalogu repozytorium do publicznego katalogu domeny. Katalog `private` musi pozostać poza katalogiem `public`.

## Budowanie interfejsu

```sh
cd frontend
npm ci
npm run build
```

Pliki produkcyjne powstają w `public/`. Są również dołączone do repozytorium. Interfejs używa API przez względny adres `api/`.

## HTTPS i logowanie

Strona logowania przechodzi na `public_url` zapisany w `private/config.php` przed pokazaniem pola PIN-u. Adres musi odpowiadać domenie certyfikatu SSL. Przy zmianie domeny zaktualizuj ten wpis.

Aby przekierować HTTP na HTTPS na standardowym hostingu OVH, dopisz na początku `public/.htaccess`:

```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
```

## Testy

Przed publikacją sprawdzono budowanie TypeScript/Vite, 13 przypadków adresów logowania, 17 kontroli Origin, 9 kontroli przepływu logowania, 34 przypadki kont i uprawnień oraz 33 dodatkowe kontrole API na PHP 8.4 i MySQL 8.0. Wcześniej sprawdzono też 56 przypadków cykli i współbieżności.

Testy `test-api.mjs`, `extras.mjs`, `test-recurrence.mjs` i instalatora wymagają osobnej testowej bazy i środowiska z konfiguracją opisaną w tych skryptach (lokalny adres `127.0.0.1:32768`). Modyfikują dane testowe. Test migracji wymaga dostarczenia prywatnej kopii — nie jest ona częścią repozytorium.

Testy adresów logowania bez bazy (Node.js z obsługą usuwania typów TypeScript):

```sh
node --experimental-strip-types tests/test-login-url.mjs
```

Test kontroli Origin, bez bazy (PHP 8.2+):

```sh
php tests/test-request-origin.php
```
