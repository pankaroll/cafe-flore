import { randomBytes } from 'node:crypto';
import { writeFileSync } from 'node:fs';
const path = new URL('../private/install-key.txt', import.meta.url);
try {
  writeFileSync(path, randomBytes(24).toString('hex') + '\n', {flag: 'wx', mode: 0o600});
  console.log('Utworzono private/install-key.txt. Odczytaj kod lokalnie i wpisz w instalatorze.');
} catch (error) {
  if (error.code !== 'EEXIST') throw error;
  console.log('Kod instalacyjny już istnieje. Zachowano dotychczasowy plik.');
}
