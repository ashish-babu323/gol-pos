# Gol POS demo — Brain-First Proposal

**Surface:** luckymart.biz/gol-pos-dev/web/ (Sunrise Mart demo) — Gol panel, chat + voice Call
**Code truth:** server copy `~/public_html/gol-pos-dev/` (brain.php Nov 1 11:01 build) — the local repo `~/workspace/gol-pos` lags the server by ~2 days; all line references below are the SERVER copy.
**Status:** investigation + proposal only. Nothing deployed, nothing written on the server.
**Date:** Sun Nov 1, 2026

---

## 0. The frame (owner's verdict, Nov 1)

> "yo just llm rules follow gariracha, real reasoning kei chaina — real reasoning hunu paryo"
> (It's just following rules; there is no real reasoning. There must be real reasoning.)

Seven live cases from one afternoon (six defects, one pass) all reduce to the same root:
the demo chat/voice is a **regex-lane machine with an LLM bolted on as a fallback composer**,
instead of a **reasoning brain with deterministic hands**. Each case below is a different
symptom of that one architecture:

| # | Owner said | Gol did | Root |
|---|---|---|---|
| 7 | "Maverick Menthol 100 purchase $2,000" (voice) | Staged a PRICE CHANGE on the previous item (Newport 100s), $2,000 mangled to $2.00 | No purchase lane exists; a context-binding price lane stole the utterance |
| 1 | "Why was today slow?" | Week total + chart ("This week: $15,575.76 across 1224 sales (today $274.78)") | The one reasoning lane exists but is starved of analytic facts and fails silently to a lookup template |
| 2 | "print the last receipt" | Capability menu (the `fallback` template) | No action lane owns receipt reprint, though the register core has the whole reprint stack |
| 3 | "print settings" | The ENTIRE settings book as one plain-text bullet wall | One global dump function answers a scoped question; no card payload |
| 4 | "what was the expenses on October" | ✅ Correct direct answer + top-3 lines | A lookup question met a lookup lane — the pattern that works |
| 5 | (on the October answer) | Purchases riding in the card on an expenses-only ask | The P&L "overview" display card rides through the Lane-4 bridge unscoped |
| 6 | Taps Call (every time) | Same canned "Hi" opener, word for word | Call greeting is a static string; live-state openers are explicitly designed out |

**Proposal: invert the stack.** A reasoning layer (the Meta Model brain that is already
wired in) reads a live context pack and composes intent + answer first. Deterministic
code keeps only two jobs: (a) exact lookups (totals, prices, stock — the Case-4 pattern),
and (b) the execution hands (stage → "gol" → execute) for writes. This mirrors the
brain-first gate the MR.STORE backend gol-chat already got: brain reasons over the pack,
deterministic dispatch executes, never the other way round.

### Mandatory guards (non-negotiable, from Case 7)

1. **Staged-write identity guard.** Before any card is staged, the card's item + action
   type are checked against the item + action the user actually named in THIS utterance.
   A mismatch (different product, or purchase-vs-price-change) blocks staging and routes
   back to the brain with the conflict stated. The card may never silently inherit an
   item from conversation context when the utterance names another product — known or new.
2. **Amount-integrity check.** The staged amount must equal the digits the user said,
   digit-for-digit after normalization ("$2,000" → 2000.00, never 2.00). The raw matched
   span is stored on the card; if normalization changed the value's magnitude (comma,
   decimal, spoken-number words), the card shows the amount back ("$2,000 — thik chha?")
   or the brain re-reads it. A $-bound fragment may never outrank the full spoken figure.
3. **Fresh-utterance rule.** An utterance naming a product starts a NEW action scoped to
   that product. Context binding ("it", a bare price after an item was discussed) applies
   ONLY when the utterance names no product of its own. A new product name — in the
   catalog or not — always breaks the previous item's gravity.

---

## 1. Case 7 — "Maverick Menthol 100 purchase $2,000" (the proof case)

**What the code does today** (server/brain.php):

- There is **no purchase-staging lane** in the POS brain. Purchases exist only as
  executed-bill records (purchases.json, read by the month lanes). The word "purchase"
  appears in no intent exclusion list.
- The price lane's gates (`golpos_amount_price_change()` L2544, `golpos_bare_price_change()`
  L2557) exclude add/receive/delivery/expense verbs — but not purchase/buy verbs — and
  `golpos_bare_price_change` fires on any **$-marked amount + a resolvable item**, no
  change-verb required.
- Inside the price lane (L6030–6110), when the named item does not resolve, the code
  falls to the context branch (L6072): *"No item in the words — but the CONVERSATION may
  be carrying one."* `golpos_ctx_product()` returns the previously discussed item
  (Newport 100s, from the owner's earlier "purchase 1000 newport 100s"), and
  `golpos_extract_price($msg, $ctxP)` mines the NEW utterance for a price against the OLD
  item, then stages `price_change` on it (L6085).
- The amount: "$2,000" came out of the extractor as **$2.00** — the comma-formatted
  figure's leading fragment won because it was $-bound; nothing anywhere compares the
  staged amount to the digits actually spoken.

**Result:** wrong item (context's, not the named one), wrong action (price change, not a
new purchase), wrong amount (1000× off). The owner cancelled; the Maverick purchase
never staged. Every element of the failure is a rules artifact, not a reasoning slip —
a reasoning layer that had read the sentence would never produce any of the three.

**Fix (in the brain-first frame):**
- Intent composition is the brain's job: "Maverick Menthol 100" + "purchase" + "$2,000"
  composes to *record a purchase of a new item* → the brain proposes the action; the
  deterministic hand stages a **purchase card** (new lane: item name as spoken, amount
  verbatim, vendor/qty asked if missing) — execution still on "gol".
- Guards 1–3 above run on every staged card, whoever composed it.
- The context-binding branch (L6072) is demoted: it may complete a follow-up ONLY when
  the utterance names no product (guard 3) and the composed intent is a price change.

---

## 2. Case 1 — "Why was today slow?" answered with a figure dump

**Code path (exact):** server/brain.php, the "DEEP-THINK lane" (L5884–5924). The regex
`(why|how come|…|slow|week|month|…)` matched, facts were assembled by
`golpos_week_facts()` (L1968), then:
1. `golpos_meta_compose(..., golpos_wonder_model(), deep: true)` — Wonder attempt
2. `golpos_meta_compose(...)` — Spark attempt
3. Both gated by `$grounded` (reply must contain a figure string from the facts)
4. **Fallback: `t('week_line')` — "This week: %s across %d sales (today %s)." (L342)** —
   this exact template, plus the 7-day bars + report card, is what the owner received.

So the routing was right and the answer still failed, for two compounding reasons:

**(a) The lane fails silently.** `golpos_meta_chat()` (server/meta.php L62) returns null
on ANY failure — no key, non-200, timeout, empty content — and nothing logs which.
The API key IS configured on this server (verified), so the failure is downstream
(API reachability from shared hosting, a non-200, a slow deep call at 60s, or a composed
reply rejected by the grounding gate for restating a figure in a different format —
e.g. "$15.6k" instead of "$15,575.76"). Nobody can tell which, because the lane keeps
no record. **Observability is part of the fix:** log http code + gate verdict per
deep-lane call (error_log or the verify journal).

**(b) The facts cannot support the judgment even when the model answers.**
`golpos_week_facts()` supplies: 7 per-day totals (gross + txns), week/month aggregates,
tender split, top-5 sellers, the full catalog. It does NOT supply anything a "why"
answer is made of — no time-of-day shape, no average ticket, no day-of-week baseline,
no partial-day fairness, no clock. At 6:11 PM the model would have had to compare a
partial today against six full days with no warning that today is incomplete.

**What the demo data actually holds for today (Sun Nov 1) — a real answer exists:**

- Today: **28 sales, $274.78, avg ticket $9.81** — and the recorded day **ends at
  11:04 AM** (hourly: 08:00→8, 09:00→13, 10:00→6, 11:00→1 sale). The demo's "today"
  is a morning, not a day.
- Same-hours fairness (sales through 11:59): Fri Oct 30 **$824.47** (67), Sat Oct 31
  **$741.54** (55), Sun Oct 25 **$572.49** (51), Mon Oct 26 **$595.65** (46). Today's
  $274.78 by 11:04 is roughly **half the morning pace** of comparable days — fewer
  customers AND smaller baskets (avg ticket $9.81 vs week's $12.73).
- **Sunday effect is structural in this data:** 26 Sundays (May–Oct) average
  **$1,136.40/day, 93 sales** vs **$1,888.76/day, 150 sales** across all 184 days —
  Sundays run ~40% under a typical day. (Last Sunday Oct 25 was an exception:
  $1,956.76 — Halloween week.)
- **Pull-forward:** yesterday, Sat Oct 31 (Halloween), was strong — **$2,683.70 across
  214 sales** — a stock-up day that plausibly drained today's demand.
- Week context: $15,575.76 / 1,224 sales / avg ticket $12.73 (matches Gol's dump —
  the lookup figures were accurate; they just weren't an answer).

**Fix:** an **analytic gate** inside the reasoning layer. A why/how/slow/trend question
triggers a computed analytic facts block (built by one deterministic function, e.g.
`golpos_analytic_facts()`), which the brain MUST reason over:

- today's gross/txns/avg ticket **and the day's recorded span** (first→last sale time,
  "today so far (data through 11:04 AM)")
- same-hours comparison: today-so-far vs the same clock window on each of the last 7
  days and the trailing-4-week average for that window
- day-of-week baseline: this weekday's average over the trailing 8–26 weeks vs the
  all-days average (Sunday effect, quantified)
- yesterday's strength (pull-forward candidate, stated as a hypothesis)
- ticket-size delta (today avg ticket vs week avg) and count delta — separating
  "fewer customers" from "smaller baskets"
- top-seller presence/absence today vs the week's top-5

And the fallback law changes: **if composition fails, the answer is a short
deterministic analytic readout built from those same computed comparisons** ("Today is
only recorded through 11:04 AM and is running at about half the morning pace of the
last week — 28 sales vs ~50 by this hour — with smaller baskets, $9.81 against a
$12.73 week average. Sundays are also this store's quietest day…"), never the bare
week-total template. The week_line template remains correct ONLY for "how much this
week" lookups.

**How the answer should read** (from the real numbers above): *"Two things are true.
First, today is only on the books through 11:04 this morning — 28 sales, $274.78 — so
it isn't a full day yet. Even hour-for-hour it's soft: by noon the last few days had
$570–$820 through the till; today had $275, and baskets are smaller too — $9.81 a sale
against $12.73 this week. Sundays are this shop's quietest day anyway (about $1,136
against a $1,889 daily average), and yesterday's Halloween Saturday was strong
($2,683.70), so some of today's trade likely happened yesterday."*

---

## 3. Case 2 — "print the last receipt" → capability menu

**Code path (exact):** No lane owns this utterance. The receipt-print-settings read lane
(L4611) requires the word "settings" near a print word — absent here. Print/receipt
utterances are deliberately carved OUT of the free-brain predicates (guards at L6352,
L6362, L6647 return false for receipt/print/settings shapes, to protect the settings
lanes). The utterance therefore falls through every deterministic lane into the free-form
section (L6228), where `golpos_meta_compose()` returned null, and dispatch ends at
**`out(['reply' => t('fallback')])` (L6266)** — the capability menu the owner received
(template at L532; the same template also fires at L4523).

**The register already HAS the whole capability** — just no chat door:
- `GET api.php?last_sale` returns today's most recent recorded sale.
- web/app.js `smReprintAction()` (L2331) fetches it and calls `showReceipt(sale)`
  (L535) — the same renderer as the original print, byte-identical by design; the
  receipt modal's Print button runs `window.print()` (L2219). Transaction History
  reprints any past sale the same way (L2465).
- But the chat client has **no handler** that maps a brain response to `showReceipt` —
  its only callers are the tender path, the Settings menu, and History.

**Correct behavior:** an imperative action, answered by acting. In the brain-first
frame the brain composes the intent *show/print last receipt*; the deterministic hand
returns a receipt payload (e.g. `{action: 'show_receipt', sale: <last_sale record>}` —
or the client fetches `?last_sale` on an action flag, one round trip it already knows);
the chat client calls the existing `showReceipt(sale)`. Gol's text reply is one line:
"Last receipt — [time], [n] items, $X (card). It's on screen; tap Print for a paper
copy." Variants that must work the same way: "reprint", "last bill dekha", "print
receipt" (bare — last receipt is the only sane referent), and "print today's receipts"
is a different, list-shaped ask. No menu, no deflection: the register has the receipt,
so Gol shows it.

---

## 4. Case 3 — "print settings" → the whole settings book as a text wall

**Code path (exact):** the settings-read lane (L4611) matches and calls
**`golpos_settings_read_reply()` (L1313)** — a single function that dumps EVERY setting
in the store as `• Label: value` plain-text lines: store name, address, phone, logo,
footer, copies, paper width, tax line, auto-open, store open/close hours, batch mode,
card surcharge, camera frame + camera audio + footage recording, refunds allowed /
refund PIN / refund window, credit redeem, and a final "Login to MR.STORE: Settings
panel" pointer line — under a header (`settings_head`, L392) that says "Receipt print
settings:". The reply is `out(['reply' => ...])` only — no card payload exists for
settings reads. (Scoped reads DO exist elsewhere — hours have `golpos_is_hours_read`,
surcharge has its own read reply — the print-settings lane just never uses a scope.)

**Why it dumps everything:** the settings live in ONE flat store (store.php
`golpos_settings()`), the label table (L1271) covers all groups in one map, and the
read function was written as "read the settings back" — the whole book — rather than
"read back the group the owner named".

**Correct behavior (standing laws: scoped answers, UI answer law, POS settings live in
chat/call):**
- The read takes a **scope** from the ask: print/receipt → receipt group only (logo,
  footer, copies, paper width, tax line, auto-open, store name/address/phone lines as
  they print). Hours, batch, surcharge, camera, refunds answer only when named.
- The answer renders as a **designed settings card** (the panel already knows how to
  draw designed cards — chatDCard in app.js; a `settings` card type with grouped rows),
  not a bullet wall. One line of text may accompany it ("Receipt settings — say
  'footer …' or 'copies 2' to change one").
- Changes already work the right way and stay: per-key staged old→new cards executed
  only on "gol" (lanes at L4615 hours, L4638 surcharge, L5457 receipt fields, camera
  lanes). The fix is read-side only: scope + card. A bare "settings" with no group
  named gets the group INDEX as a card (Receipt / Hours & batch / Card & camera /
  Refunds), one tap or one word away — never the full dump.

---

## 5. Case 4 — the PASS: "what was the expenses on October" (the working pattern)

**Code path:** the **month report lane** (L5811–5882, "owner bare-month law, Nov 1"),
which runs BEFORE the deep-think lane. `golpos_month_ref()` resolves "October";
the figure word "expenses" picks the focus; the reply is composed deterministically:
`{$expLine}` from `golpos_month_expenses()` (rows sorted desc, top-3 named) + the sales
line, with the month spark + report card attached. Answer received: **"Expenses:
$5,728.15 across 10 lines — biggest: Rent $1,850; Payroll second half $1,285; Payroll
first half $1,240"** + October sales card ($78,061.40, 6,056 sales, cash/card split —
cross-checked against sales.json: exact).

**The contrast IS the diagnosis.** This question is a *lookup* — a named period, a
named figure, one correct source — and a deterministic lane with exact routing answers
it perfectly. "Why was today slow?" is a *judgment* — it needs comparisons the lookup
layer never computed, composed by a reasoner. The current architecture routes by
keyword into lanes and hopes a composer saves the judgment questions; the brain-first
architecture classifies by *kind of question*: lookups go to the exact lanes (Case 4
unchanged), judgments go to the brain with computed analytic facts (Case 1), actions
go to the brain for intent + the hands for execution (Cases 2, 7).

*Minor nit (accepted):* the sales block rides an expenses-only answer. It's honest
context, but under the scoping law the default should shrink: expenses ask → expenses
text + an expenses-shaped card; the sales summary appears on request or on full-month
asks. (See Case 5 for where this stops being a nit.)

---

## 6. Case 5 — purchases inside an expenses answer (profit-rule separation)

**Owner's locked rule:** Profit = Sales − Purchases − Expenses − Tax. Purchases and
expenses are separate lines, never merged.

**Finding (1) — the expenses total is CLEAN.** October's 10 expense lines in
data/expenses.json, verified one by one — every line is a genuine operating expense,
no vendor/stock purchase among them:

| Date | Line | Amount |
|---|---|---|
| Oct 1 | Rent — October | $1,850.00 |
| Oct 30 | Payroll — second half October | $1,285.00 |
| Oct 15 | Payroll — first half October | $1,240.00 |
| Oct 23 | Card processing fees | $412.77 |
| Oct 5 | Electric bill — Ohio Edison | $386.42 |
| Oct 27 | Business insurance — monthly | $215.00 |
| Oct 12 | Internet & phone | $119.99 |
| Oct 19 | Store supplies & bags | $86.35 |
| Oct 9 | Water & sewer | $74.18 |
| Oct 30 | Natural gas — heating | $58.44 |
| | **Total** | **$5,728.15** |

October purchases live in their own store (data/purchases.json): **$3,657.12 across
12 bills** — Sunrise Beverage Co $2,042.52, Sunrise Snack Distributors $850.20,
Sunrise Dairy Fresh $764.40. And the month lane's expenses-focused reply TEXT is clean:
it emits the expenses line + the sales line only; the purchases line is emitted only on
purchase-focused, full-month, or compare asks, always as its own labeled line.
`golpos_cash_block()` (the report card's totals block) is likewise sales-tender only
(cash/card/credit).

**Finding (2) — the card is where purchases ride in.** The only purchases-bearing card
in the client is the **'overview' designed card** (web/app.js `chatDCard`, L1211):
rows Sales / **Purchases** / Expenses / Pay (inside Expenses) / Tax / Profit. It does
not come from the POS month lane — it rides through the **Lane-4 dcard bridge**
(brain.php L6740): when the register is linked to the MR.STORE backend "big brain", a
display card from that brain is passed through as `dcard` and rendered under the reply,
"figures untouched". On the October expenses ask, the big brain's full-P&L overview
card rode along — correct card, wrong scope: the owner asked for one line and got the
whole P&L, purchases included.

**Fix:** display cards inherit the ask's scope. An expenses-only ask renders an
expenses card (total + top lines — the exp_top rows the overview card already knows
how to draw, alone); a purchases card appears on purchase asks; the full overview card
renders only on full-month / P&L / "how is the business" asks. Wherever the lines DO
appear together (full month, compare), the separation already in the month lane is the
standard: purchases always their own line, expenses their own, tax separate, pay a
memo inside expenses — never summed into one another, in text or in cards.

---

## 7. Case 6 — Call opener: the same canned "Hi" on every call

**Code path (exact):** web/app.js `startCall()` (L2988); the greeting logic at
L2999–3007. On Call tap, the client takes *"the first Gol bubble rendered in the panel
(the panel's own greeting line)"* and speaks it through golSpeak. If that text fails
validation it substitutes the hard-coded line: **"Hi, I'm Gol. Add an item, ask a
price, or say 'today's total'."** The validation explicitly rejects any greeting that
is >200 chars or **contains a '$'** — i.e., a state-carrying opener ("$274.78 so far
today") is designed out. The comment in the code ("greeting identity (echo fix)")
shows the intent: a fixed line can never be confused with the owner's speech. The
cost: every call, forever, opens with the identical script.

Meanwhile the brain already knows how to greet a person: `golpos_unlock_greeting()`
(brain.php L3388) opens an unlock with the actor's name, time of day, role, and
standing work — it just never reaches the Call path.

**Fix (standing laws: greet by name when identity is known — ask-once; the Gol
greeting law — self-intro once, not every contact; the self-knowledge law — Gol must
know what it just said):**
- The opener is **composed at call start from live state**, server-side (one small
  endpoint or a field on the call-start response): the known person's name (session
  actor — the unlock already identified them), today's total so far + sales count,
  and anything pending that matters (a staged card waiting, an unbatched card batch,
  yesterday's unfinished tasks — the pieces `golpos_team_line()` already assembles).
  E.g. today: *"Afternoon. We're at $274.78 across 28 sales so far — a slow Sunday.
  Nothing is waiting on you."*
- **Variation is a requirement, not decoration:** the composer (brain, or 3–4 rotating
  deterministic frames filled with live figures) must not repeat the previous
  opener's shape; the client keeps the last spoken opener and the brain is told what
  it said (self-knowledge law — the same pack field that carries Gol's last question
  and last staged action).
- The echo concern that motivated the static line is real and stays handled by the
  existing guard (mic closed while Gol speaks + self-echo filter) — not by freezing
  the words.
- First-ever contact keeps the one-time self-intro ("Namaste, I am Gol…"); known
  callers are greeted by name or taken straight to business, per the greeting law.

---

## 8. The architecture, in one page

**Layer 1 — the brain (reasoning).** Every chat/voice utterance that is not an exact
lookup or a confirm/cancel token goes to the reasoning layer FIRST, with a live
context pack: shop state (today so far + recorded span, open sale/session, actor
identity), the analytic facts block (Case 1), the month facts (existing
`golpos_week_facts` pack), catalog + stock, pending/staged state, **and Gol's own last
turn** (last question asked, last action staged, last call opener — self-knowledge).
The brain returns a structured intent: `{kind: lookup|judgment|action|conversation,
action?, item?, amount?, period?, focus?}` + composed text for judgment/conversation.

**Layer 2 — deterministic lookups (unchanged, Case-4 pattern).** Totals, prices,
stock, month figures: exact functions, exact figures, designed cards. The brain may
route to them; they never impersonate reasoning.

**Layer 3 — the hands (staged writes, unchanged discipline).** Every write stages a
card and executes only on "gol" (+ PIN gates as today). Before staging, **guards 1–3**
run: identity match, amount integrity, fresh-utterance scoping. The hands execute the
card they're given; they never invent its contents from context.

**Layer 4 — actions on the register (new, Case 2 pattern).** A small action envelope
(`show_receipt`, `open_settings_group`, …) lets a composed intent move the actual
register UI through renderers that already exist.

**Fallback honesty.** Every silent-null becomes a logged event (lane, model, http
code, gate verdict). A failed judgment compose falls back to computed analytic
readouts, never to a lookup template wearing the question's clothes. A failed action
intent says what it couldn't do and offers the nearest real action — never the
capability menu (the `fallback` template is retired as an answer; it may survive as
the "help" reply when the owner literally asks what Gol can do).

**Acceptance battery (before this is called done):** the seven cases verbatim —
including "Maverick Menthol 100 purchase $2,000" spoken by voice with a Newport item
in context; "Why was today slow?" at a partial day; "print the last receipt";
"print settings"; "expenses on October" (must stay exactly as good as today, minus
the unscoped card); two consecutive Calls (openers must differ and carry live state);
plus the standing guard cases (a bare greeting cancels a staged flow; "gol" executes
only the card on screen).

---

## Appendix — key evidence

- brain.php L342 `week_line` template = the owner's Case-1 reply, verbatim shape.
- brain.php L5884–5924 deep-think lane; L1968 `golpos_week_facts`; meta.php L62
  `golpos_meta_chat` (null on any failure, no logging); API key verified configured.
- Today (Nov 1) from data/sales.json: 28 sales / $274.78 / avg $9.81; last sale
  11:04 AM; Sundays (26, May–Oct) avg $1,136.40 vs all-days $1,888.76; Sat Oct 31
  $2,683.70 / 214 sales; week $15,575.76 / 1,224 / avg $12.73.
- Case 2: brain.php L532 `fallback` template; L6266 final fallback; L4611 settings
  lane requires "settings"; guards L6352/L6362/L6647; app.js `smReprintAction` L2331,
  `showReceipt` L535, api.php `?last_sale`.
- Case 3: brain.php L4611 lane → `golpos_settings_read_reply()` L1313 (all-groups
  dump under a "Receipt print settings:" header, L392); settings store is flat
  (data/settings.json, 22 keys).
- Case 4/5: month lane L5811–5882; expenses.json October = 10 lines / $5,728.15 (all
  operating expenses, listed in §6); purchases.json October = $3,657.12 / 12 bills /
  3 vendors; overview card web/app.js L1211; Lane-4 dcard bridge brain.php L6740.
- Case 6: web/app.js `startCall()` L2988, greeting L2999–3007 (hard-coded line,
  '$'-bearing greetings rejected); brain.php `golpos_unlock_greeting()` L3388.
- Case 7: brain.php L2544/L2557 price-lane gates (no purchase exclusion); L6072
  context-binding branch; L6085 ctx price_change staging; L2724/L6018 new_item lane
  (exists, but purchase phrasing never reaches it); no purchase-staging lane exists.
