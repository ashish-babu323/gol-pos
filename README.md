# Gol POS

**A point of sale a small shop runs by chatting.**

Ring out by scan and tap where speed rules. Where thinking is needed — an item that
will not scan, a price, a product the shop has never sold, the day's close — the
cashier just talks to **Gol**, an AI assistant powered by the Meta Model API, in
their own language. Writes that change the business (prices, new items) are staged
as a card and happen only when the owner confirms.

Built from scratch during the **Meta Global AI Developer Hackathon**
(October 26 – November 4, 2026) — see `docs/BUILD-LOG.md`. The demo store,
"Sunrise Mart," is fictional; no real business data is used anywhere.

## Layout

- `web/` — the register (product grid, cart, tender, receipt) + Gol chat drawer + owner view
- `web/loyalty.html` — Receipt Loyalty customer page: join once (name / phone / email), then send receipt photos — Gol reads each receipt, dedupes by receipt number, and keeps the running total + rank
- `server/` — a small PHP API: catalog, sales, day summary, the Gol brain endpoint, and the loyalty endpoint (`server/loyalty.php`, leaderboard included)
- `data/catalog.seed.json` — the fictional demo catalog (copied to a live store file on first run)
- `docs/` — build spec and build log

## Run it

Any PHP 8 web server:

```bash
cp .env.example .env   # set the Meta Model API key path and tax rate
php -S localhost:8080 -t .
# open http://localhost:8080/web/
```

Without an API key the register still rings out (a till never stops); the Gol
chat answers from the local resolver and says plainly when the AI brain is offline.

## How Gol thinks

The brain endpoint receives a message plus the shop's own live context (catalog,
today's sales). It resolves the item the way a person would — an exact name wins,
a distinctive word wins, a genuine tie earns one clarifying question with the real
prices. Answers are composed from live figures, never pasted from a lookup.
Price changes and new items are **staged** as a card; the write executes only on
the confirm word **"gol"**.

## License

MIT
