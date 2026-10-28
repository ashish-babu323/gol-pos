# Gol POS × MR.STORE — simulation evidence (Oct 28, 2026)

Build: branch `mrstore-link` (commits 19313d0, e55f8d6, c9ce79a, f571cfd).
Deployed preview: `https://luckymart.biz/gol-pos-sim/` — `gol-pos-dev` untouched.
Linked store (the only store the bridge may write): **Muse QA Test Store**,
`22af5007-3fa8-4804-ac6a-5cf19e64c697`. QA state snapshot before anything ran:
server `~/golpos-sim-snapshots/qa-pre-20261028/` (13 files).

## How the bridge works

MR.STORE's stock truth is the MySQL `lm_ops_kv` row `product_catalog`
(fixed Floor ops owner) plus `stock_overrides`. `server/mrstore.php`
loads/edits/saves those rows with the same patterns as
`store-api/gol-chat.php` (`gol_catalog_load/save`, `gol_pos_apply_stock`,
`gol_apply_stock_counts`) — no mirror. Every sale also appends a journal
ticket by calling MR.STORE's own `lm_pos_append_ticket()` (the
`?route=pos_sale` function) into `store-api/data/pos/`. Launch binding
mirrors MR.STORE's own contract (`pos.html?store=&name=&unlock=1`, emitted
by `mrstore-paint-desk.js`): QA binds; any other store is refused with a
plain note and the register falls back to standalone Sunrise Mart (proven
live with Lucky Mart's id: bound=false, note shown, 62-product seed catalog).

## Basic proofs (public HTTPS, before the month run)

Catalog before (MR.STORE live): Coke 12pk 24 · Bud Light 3 · Ice Bag 10 ·
Pepsi 12pk 48 · Mt Dew 2 · Coke 20oz 23 · Lays 30.

| # | Sale (HTTPS) | Tender | Total | MR.STORE stock after |
|---|---|---|---|---|
| 1 | 2× Coke 12pk + 1× Ice Bag | cash $25 | $22.11 | Coke 24→22, Ice 10→9 |
| 2 | 3× Coke 20oz + 1× Lays | card | $8.06 | Coke 20oz 23→20, Lays 30→29 |
| 3 | 1× Mountain Dew 20oz | cash $5 | $2.37 | Dew 2→1 |
| 4 | 1× Pepsi 12pk (after ticket wiring) | card | $9.17 | Pepsi 48→47 + journal ticket `txn_f263551cb9` present in `sales-22af…-2026-10-28.jsonl` |

Chat (sim Gol, HTTPS): "Coke ko stock kati chha?" → "Coca-Cola 12oz Cans
12pk — 24 ota chha (MR.STORE live stock)". Low list named Dew 1, Blk Wine 1,
Backwoods 1, Dutch Grape 2, Game 2, Garcia y Vega 2, Clipper 2 — all exact.
"Ice Bag 12 ota ayo" staged a receive card (qty parsed as 12, not the 10 of
"10lb"); "gol" → "Ice Bag 10lb received — MR.STORE stock 9 → 21".
Day total after sales 1–3: $32.54 (cash $24.48, card $8.06) — exact.

MR.STORE-side read-back: independent reads of the ops_kv catalog confirmed
every decrement. MR.STORE's own Gol (CLI `gol-chat.php --email=avtarrudra8@
gmail.com --store=<QA>`) routed three stock phrasings to its day report —
its report route shadows the stock read; verification therefore rests on
the direct state reads (and on MR.STORE's own card-report below).

## The month: 100/day × 30 days (Sep 29 – Oct 28, 2026)

Runner `scripts/sim-month.php` drives the same `golpos_append_sale()` +
bridge functions as the web API. Baskets: c-store weighting, 1–5 lines,
qty 1–3, ~55% card. Opening stock seeded via MR.STORE's own count
mechanism (`mrstore_set_stock`); Mon/Thu scheduled receives on main SKUs
plus logged guard receives; every simulated sale labelled
`source: "sim-month"`; a day close per day. Oct 28 was composed as
91 runner + 4 morning proofs + 5 spot-checks rung through the public
HTTPS path (all five returned stock notes + journal tickets) = 100.
Runtime: ~11 min for 29 days (7s→23s/day as the sales log grew) + 20s.

Per-day: **100 transactions on all 30 days** (POS log count). Gross ranged
$1,588.49–$2,544.69/day. Weekly totals:

| Week | Txns | Gross |
|---|---|---|
| Sep 29 – Oct 5 | 700 | $13,601.68 |
| Oct 6 – 12 | 700 | $13,467.06 |
| Oct 13 – 19 | 700 | $13,438.12 |
| Oct 20 – 26 | 700 | $13,098.14 |
| Oct 27 – 28 | 200 | $3,696.04 |
| **30 days** | **3,000** | **$57,301.04** |

30 day-close records, 0 mismatches against the POS log.

Gol's own answer — sim chat, "yo mahina kati bikri bho?" (Muse Wonder):
> "Yo mahina October 2026 ma ahile samma $53,580.05 ko bikri bhayo. Jamma
> 2800 sales record bhayeka chhan. Tyo madhye cash $25,510.27 ra card
> $28,069.78 ako chha. Yo POS le record gareko lerako paisa ho, nafa
> hoina, nafa books ma hisab huncha."

POS-log October slice (Oct 1–28): **$53,580.05** — identical to the cent.
(The 30-day window adds Sep 29–30 = $3,720.99; Gol's "this month" is the
calendar month, stated openly.)

Sample days cross-checked line-by-line, POS log vs MR.STORE's own
`lm_card_report` over the journal:

| Day | POS | Journal tickets | Journal cash+card | Line-level |
|---|---|---|---|---|
| Oct 5 | 100 / $1,830.04 | 100 | 884.46+945.58 = $1,830.04 | ticket lines == POS lines, totals equal |
| Oct 15 | 100 / $2,016.49 | 100 | 1,040.79+975.70 = $2,016.49 | same |
| Oct 25 | 100 / $1,588.49 | 100 | 553.80+1,034.69 = $1,588.49 | same |

## Final stock table (MR.STORE live, Oct 28 after close)

Equation check two ways: (1) ordered replay of prestate + every bridge
log entry (proofs → seed sets → receives → 3,000 sales) equals live stock
on **28/28 SKUs**; (2) seeded opening + received − sold matches live
except six drink SKUs, each off by exactly the documented pre-seed proof
quantities (Coke 12pk 2, Ice 1, Pepsi 1, Dew 1, Coke 20oz 3, Lays 1).

| SKU | Opening | Received | Sold | Live |
|---|---|---|---|---|
| Coca-Cola 12oz Cans 12pk | 1300 | 1872 | 1043 | 2131 |
| Bud Light 12pk Bottles | 975 | 1408 | 872 | 1511 |
| Ice Bag 10lb | 715 | 1032 | 633 | 1115 |
| Pepsi 12oz Cans 12pk | 1105 | 1592 | 903 | 1795 |
| Mountain Dew 20oz Bottles | 910 | 1312 | 766 | 1457 |
| Coke 20oz | 1170 | 1688 | 906 | 1955 |
| Lays Chips | 845 | 1216 | 719 | 1343 |
| Marlboro Gold | 520 | 0 | 498 | 22 |
| Marlboro Red | 494 | 0 | 399 | 95 |
| Paper Towels Single Roll | 234 | 0 | 190 | 44 |
| Dutch A / B / Grape | 195 ea | 48/0/0 | 224/151/150 | 19/44/45 |
| Black & Mild / Blk Wine / Blk Smooth | 195 ea | 0/24/0 | 173/196/178 | 22/23/17 |
| Game / Swisher / White Owl | 195 ea | 0/0/24 | 148/181/219 | 47/14/0 |
| Backwoods / Show / Garcia y Vega | 195 ea | 0 | 173/169/170 | 22/26/25 |
| Optimo / Prime Time / Good Times | 195 ea | 0 | 181/187/167 | 14/8/28 |
| Clipper / Garcia | 195 ea | 0 | 158/162 | 37/33 |
| Mystery Item | 78 | 0 | 58 | 20 |

## Fencing proof

md5 of Lucky (`a0afeda8…`) and 123 (`05f636fd…`) `gol_chat`,
`product_truth`, `store_brain`, `today_loop` files — taken before the
build and after the month run: **all 8 identical**. The bridge's write
paths refuse any store id other than the QA store's.

## Findings

1. POS brain bug (fixed on this branch, f571cfd): the analysis-lane gate
   lacked `mahina`, so "yo mahina kati bikri bho?" fell into the day lane.
2. MR.STORE Gol routes stock phrasings to its day report (three phrasings
   tested) — its stock read is shadowed; worth a look in MR.STORE itself.
3. MR.STORE splits POS truth in two: the register journal (money) and the
   catalog stock (ops_kv); the Books Z is a third record fed by Night
   Close — the bridge writes the first two, exactly like MR.STORE's own
   surfaces do.
