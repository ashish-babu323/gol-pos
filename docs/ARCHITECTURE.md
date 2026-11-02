# The Gol Tree — architecture of this codebase

**One tree, one mind.** At the root sits **Central Gol** (`server/brain.php`).
Every other module in this repository is a small intelligence of its own —
it watches its own domain, reasons over its own live state, and speaks only
through Gol. Nothing talks to the user except Gol; nothing holds a private
copy of the truth. Every feature communicates with Central Gol, and through
Gol, with every other feature.

```
                          ┌─────────────────────┐
                          │     CENTRAL GOL     │
                          │   server/brain.php  │
                          │  reasons over the   │
                          │ shop's live context │
                          │ pack · stages every │
                          │ write · speaks last │
                          └──────────┬──────────┘
        ┌────────┬────────┬────────┼────────┬────────┬─────────┐
        │        │        │        │        │        │         │
     SALES    STOCK    REPORTS  LOYALTY   EYES    PEOPLE    BRIDGE
     api.php  store.php brain    loyalty  photo   invite    mrstore
     store.php pricegap  summary  .php    frame   pair      .php
                        lanes             footage
        └────────┴────────┴────────┴────────┴────────┴─────────┘
                     one shared store state (data/)
              every head reads it · only staged writes change it
```

## The heads — each one a brain, not a folder

- **Sales** (`server/api.php`, `server/store.php`) — the register's own
  record: catalog, sales journal, batches, day closes. Every figure any
  other head quotes is computed from this journal, never invented.
- **Stock / Catalog** (`server/store.php`, `server/pricegap.php`) — the
  shelf's mind. The **Price-Gap Engine** is a small intelligence inside it:
  it notices products whose price or cost is missing, and heals the gap at
  idle moments instead of letting a question fail later.
- **Reports** (the summary lanes of `server/brain.php`, fed by
  `server/store.php`) — day totals, period reports, cash/card splits,
  composed from the same journal Sales writes. A report is the Sales
  head's memory, read aloud by Gol.
- **Loyalty** (`server/loyalty.php`, `web/loyalty.html`) — the customer
  side of the tree: receipt photos are read, deduped by receipt number,
  and kept as a running total with rank. It sees only its own receipts;
  it asks Gol, never the register, when it needs the shop's facts.
- **Eyes** (`server/photo.php`, `server/frame.php`, `server/footage.php`)
  — photo intake and the branded frame: every image that leaves the shop
  through Gol carries the shop's frame. The eyes sense; they never decide.
- **People** (`server/invite.php`, `server/pair.php`) — invites and device
  pairing: who may stand at this register, and which screen is theirs.
- **Bridge** (`server/mrstore.php`) — this register is the face of a wider
  body (MR.STORE: books, storefronts, back office). The bridge is how the
  face asks the body, and how the body answers — in both directions.
- **The Conscience** (the Verify Loop tap, inside the brain's reply
  funnel) — every checkable claim Gol makes is journaled at the moment it
  is spoken, so a wrong figure can be caught against the journal instead
  of argued about. It watches the mouth itself.

## How a thought travels

1. **Sense** — a head feels something: a sale lands, a price is missing,
   a receipt photo arrives, a question is asked at the face (`web/app.js`).
2. **Wake** — Central Gol receives the message together with the shop's
   **live context pack** — the heads' current state, assembled fresh for
   that one turn. No head answers from a stale copy.
3. **Reason** — Gol composes the answer from those live facts. The Meta
   Model API (Muse Spark, via `server/meta.php`) is the primary composer;
   the deterministic engine is the silent fallback and always owns item
   resolution, staged cards and the confirm word — the register rings
   even if the API is down.
4. **Stage, never grab** — a reply that would change the business (a
   price, a new item, a write of any kind) becomes a card. It executes
   only on the owner's confirm word, **"gol"**. Prose never writes.
5. **Verify and report** — the executed write lands in the shared store
   state; the journal proves it; Gol reports the result in the same chat.
   The loop closes, or the failure speaks — nothing fails silently.

## The wider tree

This repository is the face and register of a larger organism drawn by the
owner on Oct 28, 2026: **the body is MR.STORE, the mind is Gol, the face
is this POS, and chat is its voice.** In the full tree, head AIs — Sales,
Profit, Reports, Accountant, Stock, Order Management, HR, Register,
Intelligence, Daily Works — each hold their own domain under the one
Central Gol, and only exceptions climb to the owner, with a recommended
default attached. The modules above are that tree, grown inside one
codebase: one head per domain, every leaf alive, every line running back
to the root.

## Rules the code is built by

- **One truth.** `data/` holds the shop's state; heads read it, staged
  writes change it, nobody keeps a second ledger.
- **The face never digs.** The register UI asks Gol; Gol asks the head
  that owns the truth; the head answers from what it already holds.
- **No dead features.** A module without a watcher and a next action is
  a dead limb; the Verify Loop and the batteries in `docs/` are this
  tree's pulse checks.
- **Writes are staged, speech is free.** Gol may say anything it can
  prove; it may change only what the owner confirms.
