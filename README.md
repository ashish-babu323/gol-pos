# Gol AI POS

**A point of sale a shopkeeper runs by conversation.** Gol is an AI that runs a
store's whole business — selling, stock, reports, expenses, purchases, bills,
staff — through chat and voice, with the Gol POS as its selling screen. The
owner doesn't open menus; they ask, in their own language, and Gol reasons over
the shop's live data, answers in designed cards, and stages every write for the
owner's one-word confirmation: **"gol"**. In its builder's words, it is *a very
wonderful, big, scalable project* — one engine that already runs real stores in
production, presented here on a fictional demo store, Sunrise Mart.

**Who it's for: the ground-level businessman** — the small shopkeeper at his
own counter, who is cashier, stockist and accountant in one person. Big
companies already have big software companies serving them; Gol is deliberately
built at the ground level, for him. Its job is simple: make the owner **more
aware of his own business** — what sold, what's running out, what the month
really made — and **more productive**, by moving the thinking work out of his
head and his paper notebooks into a conversation. Gol was built from a real
shop floor, for the ground-level business owner: its builder runs two real
stores himself, and every part of this tree exists because a real day behind a
real counter needed it.

Built from scratch during the **Meta / Cerebral Valley Global AI Developer
Hackathon** (October 26 – November 4, 2026) — see `docs/BUILD-LOG.md`.

## Verify it in two minutes

- **Live demo storefront:** https://luckymart.biz/s/sunrise-mart — open the Gol
  chat and just ask. Everything below works on the demo's seeded data.
- **Ask Gol things a shopkeeper actually asks:**
  - *"Give me October's report"* — a designed report card: sales, purchases,
    expenses, tax as its own line, profit.
  - *"How did October compare to September?"* — month-over-month, from the
    ledger, not a lookup.
  - *"How much were last month's expenses?"* / *"…purchases?"* — each answered
    scoped to what was asked.
  - *"How many Red Bull do we have?"* / *"What's running low?"* — live stock,
    answered by the head that owns the shelf.
  - Ring a sale by talking: *"2 Coke 20oz"* — Gol stages the line; nothing is
    written until the owner confirms.
- **The film:** the hackathon submission includes a short film of the owner
  running his real store's day through Gol — paper chaos before, conversation
  after.
- **Where things live:** `server/brain.php` is Central Gol (the reasoning
  layer), `server/` holds one small intelligence ("head") per domain,
  `web/` is the register face, `docs/ARCHITECTURE.md` draws the whole tree.

## What Gol does

- **Sells** — the POS is ~85% conversation: ring up, add, correct, tender, by
  chat or by voice Call; the panel is there for the fast, physical 15%.
- **Reports** — any period a person would name (today, yesterday, a weekday, a
  bare month, last month, a range, a year) returns the designed report card for
  that period, computed live from the closes. Profit follows the owner's rule:
  **Profit = Sales − Purchases − Expenses − Tax**, tax always its own line.
- **Knows the shelf** — stock counts, what's out, what's running low, prices —
  answered from the catalog head's own live state, never dug up per question.
- **Handles the paperwork** — a delivery bill is scanned and learned (vendor,
  lines, pattern); after a few bills Gol drafts the next order. A photo sent in
  chat becomes a framed, branded post, staged for the owner's confirmation.
- **Remembers the money honestly** — in production the same engine reconciles
  a live bank feed against the books: matched items pass silently, unmatched
  debits are brought to the owner to name, then entered exactly once.
- **Speaks the owner's language** — Nepali, English, or the mix a real counter
  actually speaks; the answer mirrors the language of the question.

## The demo dataset

Sunrise Mart is fictional — and it is not a toy. The demo store carries **six
months of coherent seeded history (May–October 2026): 27,542 transactions,
31 October closes**, a real catalog with stock levels, expenses and purchases —
so Gol can answer *any* month, comparison, or trend question live, the way it
would for a store that has run on it for half a year. No real business data is
used anywhere in this repository.

## The scale — one tree, many nodes

Gol is not one chatbot bolted onto one till. It is a **tree**:

- **Central Gol** sits at the owner's CEO desk and holds the whole network in
  front of it.
- Every business running Gol is a **node** — a store, a studio, a school —
  each running the same strategy: a reasoning layer that first assembles the
  node's **live context pack** (open sale, closes, cash/card, stock, prices,
  people), decides what the person means, and only then acts.
- Every node is itself a **head node**: a store's Gol heads its POS Gol, its
  storefront Gol and its Books Gol; under each sit dedicated **head
  intelligences** per domain — Sales, Stock, Reports, Accountant, People,
  Daily Works — each holding its own domain's truth.
- **A node's data stays inside the node.** No sibling sees another's books;
  Central Gol alone sees across the nodes the owner owns.

Safety is structural, not promised: writes are **staged as cards** and execute
only on the owner's confirm word **"gol"**; the ledger is **append-only**
(corrections are new entries, never edits); every figure Gol speaks is computed
from the journal at the moment it is spoken.

The full drawing, mapped to this code: `docs/ARCHITECTURE.md`.

## Why it scales

- **One engine, many nodes.** A new business onboards as a new node on the
  same architecture — same brain, same heads, its own data. The tree grows by
  adding branches, not by rebuilding.
- **Isolation by design.** Because a node's data never leaves the node, nodes
  can multiply — stores, clients, whole networks — without cross-contamination.
- **Storage that migrates on load, not on hope.** The flat-file store migrates
  when concurrency demands it, triggered by measured load rather than client
  count.
- **Change rolls out node by node.** Per-node versioning with a
  dev → QA → battery → canary → staged rollout path means one node's upgrade
  never risks the fleet.
- **The interface is conversation.** Onboarding a shopkeeper needs no manual
  and no training: they already know how to ask.

## Honest scope

- Everything in this repository runs on **fictional Sunrise Mart data**.
- The same Gol engine also runs **two real stores in production — Lucky Mart
  (Parma, Ohio) and 123 Grocery & Smoke (Cleveland, Ohio), both run by the
  builder himself** — plus client sites. That production data is private and appears nowhere here; the claim
  is stated so a reviewer understands this is a working system with a demo
  face, not a demo with ambitions.
- This repo is the POS node of the tree: the register face, Central Gol's
  brain endpoint, and the heads that live with the register. The wider tree
  (books, storefronts, bank feed, the CEO desk) is drawn in
  `docs/ARCHITECTURE.md`.

## Layout

- `web/` — the register (product grid, cart, tender, receipt) + Gol chat drawer + owner view
- `web/loyalty.html` — Receipt Loyalty customer page: join once (name / phone / email), then send receipt photos — Gol reads each receipt, dedupes by receipt number, and keeps the running total + rank
- `server/` — a small PHP API: catalog, sales, day summary, the Gol brain endpoint, and the loyalty endpoint (`server/loyalty.php`, leaderboard included)
- `data/catalog.seed.json` — the fictional demo catalog (copied to a live store file on first run)
- `docs/` — architecture, build spec and build log

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

Every turn, the brain endpoint receives the message **together with the shop's
live context pack** — catalog, open sale, today's and past closes, stock —
assembled fresh for that turn. Gol reasons over the pack first: what is this
person trying to do — sell, ask, change, report — and what does each number
mean (a quantity is not a price). Item resolution works the way a person
would: an exact name wins, a distinctive word wins, a genuine tie earns one
clarifying question with the real prices. Rule lanes then execute as fences.
Answers are composed from live figures, never pasted from a lookup. Price
changes and new items are **staged** as a card; the write executes only on the
confirm word **"gol"**.

## License

MIT
