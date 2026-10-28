# MR.STORE parity map — every menu / link / button, and its Gol chat door

Owner's bar: everything MR.STORE shows on a screen must be reachable as a
Gol chat action or answer. This map enumerates the real surfaces found in
the live app code (public_html app JS + store-api) and marks each one:

- **WIRED-IN-SIM** — works in this Gol POS × MR.STORE simulation build.
- **ALREADY CHAT-NATIVE** — MR.STORE's own Gol (store-api/gol-chat.php)
  already does it by chat; not part of this POS sim's scope.
- **REMAINING** — screen-only today; no chat door yet. Stated plainly.

## WIRED-IN-SIM

| Surface (where it lives) | Chat door |
|---|---|
| POS launch link in desk/settings (`mrstore-paint-desk.js` `fillPosStoreLink` → `pos.html?store=<id>&name=<name>&unlock=1`) | The Gol POS register accepts the same launch params (`POST {bind}`); the named store binds, any other store is refused with a plain note + standalone register. |
| Register catalog (`pos.html` loadCatalog ← ops_kv `product_catalog`) | The sim register serves the linked store's live catalog — real store name, live on-hand counts. |
| Ring a sale (`mrstore-pos.js` postSaleCloud → `POST /store-api/?route=pos_sale`) | Sim register sale appends the same journal ticket by calling `lm_pos_append_ticket()` (card-report.php) — `data/pos/sales-<store>-<day>.jsonl`. |
| A sale moves stock (`gol-chat.php` `gol_pos_route` → `gol_pos_apply_stock`) | Bridge `mrstore_record_sale()` mirrors it: same catalog rows, same stock_overrides patch. |
| Stock question (MR.STORE Gol stock reads) | Sim Gol chat: "Coke ko stock kati chha?" — answered from MR.STORE live state. |
| Out / low stock (What's Out, stock reads) | Sim Gol chat: "kun kun item sakina lagyo?" — out/≤2 list from live stock. |
| Stock receive / delivery (Floor stock apply) | Sim Gol chat: "Ice Bag 12 ota ayo" → staged card → "gol" → MR.STORE stock rises. |
| Day total / Z read (desk "Log Z now", Gol day report) | Sim Gol chat: "aaja kati bikri bho?" — POS till figure, source-labelled (till vs MR.STORE). |
| Day close (`pos.html` Z-close button; `golpos_close_day`) | Sim Gol chat "close the day" freezes the day record; the month runner closes every simulated day the same way. |
| Card report (`?route=card-report` over the journal) | Sim sales are in the journal, so MR.STORE's own card report reads them — verified for sample days in the simulation report. |

## ALREADY CHAT-NATIVE in MR.STORE's own Gol (gol-chat.php)

| Surface | Chat door |
|---|---|
| Expense entry (Books) | Staged expense card in chat, executes on the confirm word (seen verbatim in the QA store's chat history). |
| Vendor bill / purchase photo (Bills, bill vault) | Photo → lines read → staged → entered; stock applies via `fb_apply_bill_stock`. |
| Stock count photo (Floor) | `gol_stock_count_card` → `gol_apply_stock_counts` sets counts from a count sheet/photo. |
| What's Out list | `gol_append_whats_out` — chat adds items to the out list. |
| Tax question (Books tax figures) | `gol_tax_route` — collected/paid answered from the ledger. |
| Best day / sales analysis | `gol_best_day_route` + the Meta brain over the store's own pack. |
| Fuel sale / delivery (Fuel hub) | `gol_fuel_*` staged cards. |
| Offer poster (Social) | Poster lane (`gol-poster.php`, Muse Image) — staged, publishes on the owner's Yes. |
| Register sale typed in chat (POS route) | `gol_pos_route` — a typed sale stages and applies stock on confirm. |
| Week / month money reports | Meta brain over the ledger pack ("yo mahina kati kamai?"). |

## REMAINING — screen-only, no chat door yet

| Surface | Note |
|---|---|
| **Card Batch page** (`pos-batch.html`, `?route=card-batch`, `lm_batch_*` in batch.php) | A **card settlement batch**: it closes a day's CARD tickets from the register journal with fee %, payout days and a bank date; a night job auto-closes it and emails the deposit date. This is a **different record** from the Gol POS day-close (`closes.json` — the till's day record). Sim sales feed the journal the batch is built from, but no chat action in this sim closes a card batch, and the sim never writes batch state. |
| Square / Clover connect & sync (`mrstore-pos-sync.js`, `mrstore-square-api.php`, `mrstore-clover-api.php`) | Processor OAuth + sale sync; no chat door. |
| Card payment config (`mrstore-pos-stripe.php`) | Settings screen; not chat-run in the sim. |
| Register station list (`mrstore-pos-register.php`) | Presence ping/list of register IPs — desk visibility only. |
| POS offline queue, void, return mode (`mrstore-pos.js`) | Register-local functions; the sim Gol POS has no chat void/return. |
| Storefront + customer chat (`/store/`, `/s/<slug>`) | Customer surface with its own Gol — separate from the owner POS. |
| Books full screens (payroll, reconciliation, Money Tree) | Gol stages entries by chat; the full Books screens remain screens. |
| Staff / employees (desk "Staff", boost-employees) | Roster/hours/pay are screen-run today. |
| Social engines (reels, engagement, lotto boards) | Cron/engine-run; only the poster lane is chat-native. |
| Store profile settings (`shop_profile`: hours, tax) | The bridge reads name/profile; the sim POS does not write shop_profile. |

Counts: **10 wired in this sim · 10 already chat-native in MR.STORE Gol · 10 remaining.**
