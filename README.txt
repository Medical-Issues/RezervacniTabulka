# Projekt: Rezervační tabulka jako podstránka na webu školky (Node.js + Express)

## Popis
Tento projekt je jednoduchý server postavený na Node.js a Expressu, který poskytuje podstránku s rezervační tabulkou. Aplikace by měla běžet trvale na serveru.

## Požadavky
- **Node.js**
- **NPM**
- Otevřený port **3000** (nebo jiný dle nastavení serveru)

## Instalace a spuštění
1. Nahrajte soubory na server
  
## Nainstalujte závislosti:
npm install

## Spusťte aplikaci:
node index.js

nebo pokud používáte nodemon:
npm run dev

## Nasazení na produkci (optional, ale pokud je často restartován server je to lepší aby se nemusela aplikace ručně zapínat, jinak samozřejmě není nutné.)

npm install -g pm2
pm2 start index.js --name moje-aplikace
pm2 save
pm2 startup

## Konfigurace
Pokud aplikace používá proměnné prostředí (.env soubor), ujistěte se, že jsou správně nastavené. Projekt soubor .env obsahuje

Pokud běží na jiném portu než 3000, lze změnit v souboru server.js na řádku 26.


Kontakt
Pokud je potřeba něco upravit, kontaktujte mě na veselsky.honza@gmail.com
