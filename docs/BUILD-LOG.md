# Gol POS — Build Log

Built during the Meta Global AI Developer Hackathon, October 26 – November 4, 2026.
One entry per day; the git history carries the same dates.

## Day 1 — Mon Oct 26, 2026
- Project created from scratch after the hackathon kickoff confirmed the demo may
  show only work built during the event.
- Frame committed by Muse (manager/operator): product spec, data model, JSON
  store, register API (catalog / sale / summary / pending), the Gol brain core
  (item resolution, price query, staged price change, add-to-cart, day summary;
  writes execute only on the confirm word "gol"), register web skeleton with the
  full-screen Gol chat drawer, fictional "Sunrise Mart" demo catalog (24 SKUs).
- Build split: Dev — register UI polish and owner view; Grok — catalog expansion
  to ~60 SKUs + test battery; Muse — brain and QA gates.
- Gates: Wed Oct 28 register core end-to-end · Fri Oct 30 brain battery (>=90%)
  · Sun Nov 1 full demo run x3 + public-repo check · Mon Nov 2 freeze and film ·
  submit Nov 2–3 · deadline Wed Nov 4, 9:00 PM ET.
- **Gol AI POS — built with Muse (Meta Model API).** The submission story,
  owner-locked Oct 26: "MR.STORE was already a platform. Then Muse arrived —
  and with Muse's APIs, we built Gol: the brain that runs it by chat."
- Brain provider rewired so the code tells the same story: the brain shipped
  this morning as a deterministic intent engine with no model behind it. Now
  **muse-spark-1.3 via the Meta Model API is the PRIMARY composer** of Gol's
  replies — price answers, the day-summary line, and free-form turns are
  written by Muse from the shop's live facts, and accepted only when the
  reply carries the exact figure it was given (grounding check). The
  deterministic engine remains as the silent fallback and still owns item
  resolution, staged cards and the "gol" confirm — writes never depend on
  the API, and the register rings even if the API is down. The key stays
  server-side (env var, or an env file outside the web root holding a
  META_MODEL_API_KEY line); every brain response carries a `meta` marker
  (`provider: meta-model-api | deterministic`, `model`) outside the UI.
- Live proof on the dev preview (Oct 26 evening, one test sale of
  2× Coca-Cola rung and then rolled back): "How much is a Coke?" →
  "A Coca-Cola 20oz costs $2.19." · "What is todays total?" → "Today's total
  is $4.73 across 1 sales — cash $4.73, card $0.00." · "Coke ko mulya kati?"
  → "Coca-Cola 20oz ko mulya $2.19 ho." (language law holds through the Muse
  composer). All three responses: `meta.provider = meta-model-api`,
  `model = muse-spark-1.3`. Live data restored afterwards: catalog.live is
  byte-identical to the seed, Coke back at $2.19, no pending card, no test
  sale left behind.
- **The full Muse family inside Gol AI POS — Spark thinks fast, Wonder
  thinks deep, SAM sees, Muse Image creates** (owner order, Oct 26 evening).
  One server-side client (`server/meta.php`) now serves all four models off
  the same protected key; `resolve_item()` moved to `store.php` so every
  lane shares the resolver. The lanes:
  - **Wonder (muse-wonder-1.0) — deep think.** Judgment questions (why /
    how was / week / suggest / kina / kasto…) route to a new analysis
    branch: the deterministic layer assembles the week's facts (per-day
    totals, week aggregate, tender split, top sellers) and Wonder composes
    the judgment under the same figure-grounding check; Spark composes if
    Wonder is unreachable, the template speaks if neither answers.
    Register-speed turns stay on Spark.
  - **SAM 3.1 — the eyes.** New `server/photo.php` + a 📷 button in the
    chat: the owner uploads a product/shelf photo with an optional concept
    hint; SAM segments it via the Responses API and the read stages as a
    `photo_read` card (photo, concept, region count, boxes) — candidates
    for the owner to confirm, never silent truth. On "gol" a matched
    catalog item goes to the cart; an unmatched read is only logged. If
    SAM doesn't answer, the photo still stages with an honest no-read note.
  - **Muse Image (muse-image-1.0) — creates.** "Make an offer poster for
    Coke" resolves the item + catalog price, generates the poster, shows
    the preview in chat as a `poster` card, and publishes ONLY on "gol" —
    into the demo store's feed (`data/feed.json`, shown in the owner
    view). Fictional Sunrise Mart branding only.
  - Live proof on the dev preview (one small call per model; a fictional
    week of 40 sales / $235.27 seeded for the Wonder test, today
    deliberately quiet at $2.37, then removed): Wonder — "Why was today so
    slow? How was this week overall?" → composed analysis carrying the
    exact figures ($2.37 today · $235.27 week · top seller Pringles Sour
    Cream 2.5oz — $36.64) + one grounded suggestion, `meta.model =
    muse-wonder-1.0`. SAM — the generated poster uploaded back with prompt
    "bottle" → 1 region, box x1=430 y1=652 x2=719 y2=1514 in the 1152×2016
    frame, `meta.model = sam-3.1`; card confirmed with "gol" and logged,
    nothing auto-added (no catalog match for "bottle"). Muse Image —
    poster for Coca-Cola 20oz at the catalog $2.19, PNG 1152×2016 at
    `web/uploads/posters/poster-20261027-010029-2ee738.png`, `meta.model =
    muse-image-1.0`; confirmed with "gol" → the one entry now in the demo
    feed (kept as demo content). Regression: Spark price path re-verified
    after the refactor ("Coca-Cola 20oz costs $2.19.", `muse-spark-1.3`).
    Hygiene afterwards: seeded sales + photo log + test upload removed,
    catalog.live byte-identical to the seed, Coke $2.19, no pending card.
    Note: heavy scripted curl from the build machine trips the host's
    bot-check page after several calls — late checks were run over SSH
    loopback instead; real browsers are unaffected.
- **Gate-2 brain battery: 60/60** (late Oct 26). Grok's battery set
  (`docs/brain-battery.gate2.json`, six intents × EN / Romanized NP /
  Devanagari + the reply-language rule) scored 29/60 against the morning
  brain. The brain lane closed the five gaps in the deterministic layer —
  Devanagari intents (price-change verbs, चाहियो, Devanagari digits), a
  real new-item intent that stages cards (price asked, never invented),
  a close branch that writes the day record (`closes.json`, readable at
  `api.php?closes`), widened day-total phrasings, word-start alias
  matching — plus stem-based reply-language detection. Rerun against the
  live preview: baseline 30/60 → 59/60 → **60/60** on the final full run,
  with the Day-1 spot check (Coke $2.19 → staged 2.50 → "gol" executes)
  reproduced and all test state rolled back byte-for-byte. Full
  case-by-case results: `gate2-battery-results-2026-10-26.md` in the
  hackathon pack.
- **Receipt print settings — one store, two doors** (late Oct 26). Print
  settings moved out of any menu and into Gol's two doors: the chat (read
  on "print settings"; changes staged as old -> new cards, executed only on
  "gol") and the owner panel (an editable form over the SAME
  `data/settings.json` — `golpos_settings()`/`golpos_save_settings()` in
  store.php, `?settings` + POST in api.php). Settings: store name, address
  (+show), phone (+show), Gol ring logo, footer, copies 1-3, paper width
  58/80mm, tax line, auto-open receipt. The register renders every receipt
  from them (header/footer lines, copies with cut marks, width classes +
  named @page print rules, logo toggle, auto-open toast), and the receipt
  modal gained a Print button. The owner panel also gained the manual
  Reports card: Today / Yesterday / This week / date picker over the same
  day-summary data the chat speaks (`?report`), close records shown marked
  Closed, and a Close-the-day button running the chat's own close path
  behind a tap-confirm. Settings battery S1-S10 + manual-door M1-M3:
  **13/13** against the live preview; Gate-2 re-run after the build:
  58 clean passes + 2 host-503 cases re-verified by hand — no assertion
  failed, battery definition untouched. All test state restored
  byte-identical (catalog == seed, settings = defaults). Full results:
  `pos-print-settings-battery-2026-10-26.md` in the hackathon pack.

## Day 2 — Tue Oct 27, 2026
- **Cash tender step completed (Oct 28 register-core gate, pulled a day early).**
  The Cash button no longer records a blind sale: it opens a cash panel with
  the total due, a cash-received field, quick-add chips (+$1/$5/$10/$20/$50,
  Exact, Clear) and a live change-due / still-needed readout; the sale can
  only complete when the tender covers the total. The server repeats the
  check — a cash sale records `tendered` + `change_due`, an under-tendered
  sale is refused (422) and records nothing, and a sale with no valid
  catalog lines is refused instead of recording $0. The receipt now prints
  "Cash given" and "Change" lines from the recorded values. Cart totals are
  rounded to cents in the UI so the panel, the display and the server agree
  to the penny. QA on a throwaway copy (never the demo data): scan barcode
  049000000443 -> Coca-Cola 20oz; 2x Coke + 1x Pringles = $6.67 + $0.53 tax
  = $7.20; tendered $10 -> change_due $2.80; under-tender $5 -> 422, nothing
  recorded; invalid-SKU-only sale -> 422; card sale + summary split verified
  ($9.57 gross = $7.20 cash + $2.37 card, 2 txns). JS node --check clean,
  PHP -l clean, every DOM id the new panel uses is present in index.html.

## Day 3 — Wed Oct 28, 2026
- **Receipt Loyalty built (owner addition, Oct 26 — rides the Oct 30 photo gate).**
  The Festival concept as a Gol POS feature, on the fictional Sunrise Mart data:
  - Customer page (`web/loyalty.html` + `web/loyalty.js`, mobile-first, the blue
    Gol identity): join ONCE with name / phone / email — the phone is the master
    key and is kept on the customer's own device — then send a PHOTO of each
    receipt in a simple chat-style flow.
  - Server (`server/loyalty.php`): Muse Spark vision-reads the receipt photo
    (store, date, grand total, receipt number) through a new vision helper in
    `server/meta.php` (chat-completions multimodal first, the Responses shape as
    fallback; the same server-side key home — nothing client-side ever holds a
    key). The receipt saves under the customer in loyalty's OWN store file
    (`data/loyalty.json`, seeded empty; the catalog seed and sales formats are
    untouched) and Gol replies with the running total + rank. Dedupe is by
    receipt number per store — the same receipt counts once, whoever sends the
    photo. A photo the vision lane cannot read counts NOTHING and earns an
    honest retake ask — no figure is ever invented.
  - Owner view: the leaderboard (name, total spent, receipt count, rank) is
    computed from the saved receipts on every read, so it can never drift from
    what customers were told — a new section in the register's Owner panel, and
    the customer page shows the same board with the customer's own row lit.
  - Verified end-to-end on a throwaway copy (PHP built-in server; the repo's
    and the live preview's data untouched — repo `data/` still holds only the
    seed): two rendered Sunrise Mart receipts read back EXACTLY (SM-10482 →
    $12.92, SM-10497 → $9.36, both dated 2026-10-28, provider marker
    meta-model-api / muse-spark-1.3 on every read); the same receipt re-sent →
    "already counted", totals unchanged; the same receipt sent by a second
    customer → deduped too; a non-receipt image → nothing counted; an
    unregistered phone → join-first reply; leaderboard Maya Sharma #1 $12.92
    (1 receipt) / Dev Patel #2 $9.36 (1 receipt); brain smoke test ("How much
    is a Coke?" → "A Coca-Cola 20oz costs $2.19.") unchanged. PHP -l clean on
    every server file, node --check clean on both JS files.
- **Access management + batch close + full chat parity (owner directive, Oct 28 — the Oct 30 gate set).**
  The owner panel stays exactly as it was — the law is parity, not removal: nothing may
  REQUIRE the backend screen, so every function the panel shows is now fully answerable
  and stageable in the Gol chat.
  - **Two domains, one chat.** Cashier domain (never gated): ringing sales, add-by-name,
    price queries, today's running total, poster creation, settings reads, and the
    STAGING of any card. Admin domain: report questions beyond the running day total,
    the day-close record, batch close, employee management, the loyalty leaderboard —
    and the EXECUTION of staged price / new-item / setting / batch / employee-remove
    cards, gated at the confirm word (a non-admin saying "gol" to an admin card gets
    the PIN demand first; the card executes the moment the PIN verifies).
  - **The admin PIN gate** (`server/brain.php` access layer over `data/people.json` +
    `data/sessions.json`): chat calls carry a client session id (localStorage); the
    server keys elevation to it. No PIN yet → the first admin intent runs a create-PIN
    flow (enter a 4–6 digit PIN, once more to confirm; `password_hash` at rest).
    Unelevated admin intent → Gol asks for the PIN and NOTHING else (no figures, no
    hints), keeps the original message server-side, and replays it through the intent
    dispatch on success — the PIN turn answers the original question in one reply.
    A typed PIN is consumed by the gate, never dispatched, never logged as text (the
    response's `pin_mask` flag makes the client render the bubble as ••••); 3 wrong
    PINs lock elevation for 10 minutes; "lock" / "admin bata bahira" drops it. The
    dispatch now runs as one function so the gate can replay into it, and the confirm
    check moved above the empty-message check — the register's Confirm button posts
    {confirm:true} with no text and previously met the "type something" reply instead
    of executing its card; it executes now.
  - **Employees** (admin domain): "add Ram <email> as employee" creates the invited
    record and sends a one-time invite email via PHP `mail()` in Gol's voice (link to
    the new `web/invite.html`); if `mail()` fails the reply says so plainly and carries
    the link — never a pretend send. `server/invite.php` validates the token by hash,
    the employee sets their own 4–6 digit PIN (status → active, invite token burned),
    and the device token it issues rides with that device's chat calls. An identified
    employee holds the cashier domain only — admin questions demand the ADMIN PIN;
    the employee PIN never elevates. List and staged remove ("remove <email>" + "gol")
    included.
  - **Batch close** (`data/batches.json`): segments are computed from sale ids, so a
    segment can never seal twice and cash never batches. "batch close gara" stages the
    unbatched card segment (count / total / period); "gol" seals batch #N; no unbatched
    card sales → said plainly, nothing staged. Day close and the Reports API compose
    WITH batches — the day's batches are named and any unbatched card remainder is
    flagged, never skipped.
  - **AUTO batch mode (owner addition, same day):** new settings `store_open` /
    `store_close` / `batch_mode` in the shared settings store, settable in chat as
    staged setting changes ("batch auto gara", "store closes at 9 PM", "store 9 baje
    banda huncha") and readable ("store hours kati ho?"). The POS is request-driven,
    so the seal is lazy: every API/chat call first runs the sweep — in auto mode, past
    today's close (compared as a full timestamp; a minute-granularity compare held the
    seal a beat too long in the first battery run and was fixed), today's unsealed card
    segment seals itself with `closed_by: "auto"` and the close time recorded, at most
    one auto batch per day. Day close names it: "Batch #N auto-closed at 9:00 PM —
    12 card sales, $XXX". A manual close earlier that day leaves nothing for the pass.
  - **Verified on a throwaway copy only** (PHP built-in server, invite mail sunk to a
    file — no real email sent; repo and preview data untouched): access battery 43/43 —
    cashier price/day-total/add-by-name open; first admin ask runs create-PIN with zero
    figures leaked; wrong PINs refused with tries counted; correct PIN answers the
    pending question and the next admin question needs no PIN; non-admin "gol" on a
    staged price change demands the PIN before the price moves (verified unchanged,
    then executed on the PIN, then restored); employee added → record invited + invite
    captured in the mail sink with a working token → PIN set → active → employee's
    admin ask demands the ADMIN PIN and the employee PIN is refused as one; batch:
    2 card sales stage with exact count/total → "gol" seals batch #1 → second close
    stages nothing → day close names batch #1 with no unbatched warning; loyalty
    leaderboard answers from the store (Maya Sharma #1, $24.50) for admin and demands
    the PIN from a cashier; "lock" re-gates; no plaintext PIN anywhere in the stores.
    Auto-batch battery 16/16 — hours read open; manual mode seals nothing past a past
    or future close; mode switch stages for a cashier but executes only past the PIN;
    after switching to auto, the next call seals the waiting segment as batch #2
    (auto, correct count/total/close time), never twice; the day close names it.
    Employee remove (staged + "gol"), settings read with the new lines, and a Nepali
    PIN demand with no leak spot-checked by hand. PHP -l clean on every server file,
    node --check clean on app.js + invite.js; asset stamps bumped to the client
    commit per the static-cache law.
- **PIN gate: report classification fixed (follow-up, Oct 28).** The gate caught
  English period words ("last week ko report dekha" was gated) but missed Nepali
  report asks — a fresh cashier asking "hijo ko report dekha" got today's-total
  fallback with no PIN demanded. New shared matcher `golpos_is_report_intent` in
  server/brain.php, called from the gate ahead of the settings-word return:
  (figure nouns — report/hisab/bikri/sales/total/kamai — or the "kati …
  bhayo/thiyo" shape) crossed with a NON-today time reference (hijo/asti/parsi/
  gata/last/previous/kal/agadi, hapta/hafte/mahina/mahine week-month periods,
  explicit dates, month names; Devanagari included). Guards keep the cashier
  domain intact: a pure today ask ("aaja kati bikri bho?") never carries a
  non-today reference, and a price-of-item query naming a day ("hijo Coke ko
  price kati thiyo?") stays a price answer. Evidence (throwaway copy): Nepali
  battery 10 fails pre-fix → 17/17 post-fix, every gated reply showing zero
  figures; original access battery still 43/43.

## Day 3 (Oct 28) — MR.STORE link: Gol Connect simulation (branch mrstore-link)

- server/mrstore.php (new): the bridge. It speaks MR.STORE's own storage
  language — MySQL lm_ops_kv rows under the fixed Floor ops owner, loaded
  and saved with the same patterns as store-api/gol-chat.php
  (gol_catalog_load / gol_catalog_save / gol_pos_apply_stock /
  gol_apply_stock_counts), including the stock_overrides patch and the
  gol-backups backup convention. No mirror file exists: a write here is a
  write in MR.STORE.
- Fencing: every write path refuses unless the configured store id is the
  Muse QA Test Store (22af5007-…). Reads are store-scoped the same way.
- Wiring (all behind LINK_MRSTORE, default off; standalone POS unchanged —
  smoke-tested link-off: seed catalog, sale, day total all normal):
  golpos_catalog() serves the linked store's catalog (real store name,
  live onHand, MR.STORE prices; POS-side demo prices only where MR.STORE
  has none, never written back); a completed sale calls
  mrstore_record_sale() so MR.STORE stock moves; the Gol chat gains a
  stock lane (one item's stock, out/low list, staged stock-receive card
  executing on "gol") answered from MR.STORE live state; day-total
  answers name their source (POS till vs MR.STORE); owner price changes
  and new items sync back into the MR.STORE catalog.
- scripts/sim-month.php: the month runner — drives the same
  golpos_append_sale + bridge functions in a loop (100 txns/day × 30
  days), c-store-weighted baskets, twice-weekly + guard receives, a day
  close per day, every simulated sale labelled source=sim-month.
- Adjustment (owner pointer, same day): MR.STORE already ships its own
  POS — pos.html launched from the desk/settings paint JS as
  pos.html?store=<id>&name=<name>&unlock=1; its sales post to
  /store-api/?route=pos_sale, which appends a ticket to the register
  journal (store-api/data/pos/sales-<store>-<day>.jsonl via
  lm_pos_append_ticket in card-report.php); stock moves separately via
  gol_pos_apply_stock. The bridge now mirrors BOTH halves: every Gol POS
  sale also appends its journal ticket by calling lm_pos_append_ticket
  itself. The Gol POS register accepts the same launch params: app.js
  records ?store=&name=&unlock= via POST {bind}, the link engages only
  when the named store is the configured one, and any other store is
  refused with a plain note + standalone fallback. MR.STORE's card batch
  (pos-batch.html, lm_batch_* over the journal) is a settlement batch —
  a different record from this POS's day-close; the parity map says so.
- Simulation executed (Oct 28): deployed to gol-pos-sim (gol-pos-dev
  untouched), basic proofs over HTTPS (3 sales, stock Q&A, staged
  receive, binding contract incl. a refused Lucky launch), then the
  month run — 100 txns/day × 30 days (Sep 29–Oct 28), 3,000 sales,
  $57,301.04 gross; every day closed; stock equation verified 28/28 by
  ordered replay; 3 sample days cross-checked line-by-line against
  MR.STORE's own card report over the journal; Gol's month answer
  ($53,580.05, October) matches the POS log to the cent; Lucky + 123
  state files md5-identical before/after. Full evidence:
  docs/mrstore-sim-evidence.md. Parity: docs/mrstore-parity-map.md
  (10 wired-in-sim · 10 already chat-native · 10 remaining).

## Day 3 (Oct 28) — MERGE: the access build × the MR.STORE link land on master (41c8c00)

- Branch `mrstore-link` (bridge + month simulation) merged into master
  (access layer + Nepali report gate). Three textual conflicts, all
  resolved by hand as unions, never by taking a side: BUILD-LOG keeps
  both Day-3 entries; index.html keeps the access chips (the script
  stamp line was the only collision); brain.php's confirm handler keeps
  BOTH card-execution sets — batch_close + employee_remove from master,
  stock_receive from the branch, sharing the one card_fail tail.
- Interaction rules in the merged tree: link mode stays behind
  LINK_MRSTORE (default off; standalone Sunrise behavior is the
  default). `stock_receive` joins the gate's admin card-type list, so a
  stock write executes only past the PIN, exactly like a price change;
  stock reads stay cashier domain, like price queries. The gate still
  classifies first (report/month/stock-admin asks follow the admin
  rules), and the branch's one-word `mahina` analysis-lane fix
  survives, so an elevated admin's month question gets a real month
  answer instead of the day lane. web/app.js gains a stock_receive
  card renderer (the generic card body would have shown the receive's
  qty as a price). .gitignore covers the link runtime stores
  (link.json, bind.json, mrstore_link_log.jsonl).
- Verified on the merged tree (php -l + node --check clean throughout):
  access battery 43/43 + auto-batch battery 16/16 + Nepali gate battery
  17/17 on throwaway copies (mail sunk). Standalone regression on a
  throwaway server dir: 62-product Sunrise catalog, Coke $2.19, cash
  sale $4.73 with change, day total, Receipt Loyalty register →
  SM-10482 counted $12.92 → duplicate rejected → leaderboard correct.
  Link-lane proof on the same dir with LINK on + QA binding: launch
  bind engaged for the QA store only, stock question read MR.STORE
  live (Coke 12pk 2131 — the sim month's closing shelf), ONE card sale
  moved MR.STORE stock to 2130 with its journal ticket written, and a
  fresh session's "yo mahina kati bikri bho?" demanded the PIN, then
  answered $14.44 (the two test sales) after create-PIN elevation.
  The throwaway server dir was deleted completely afterward.

## Day 3 (Oct 28) — Settings dropdown + Close-the-day to the top of the Owner panel (36f30c5)

- Owner decisions: the counter operations go behind ONE Settings
  dropdown in the register header (by Owner / Talk to Gol) — "baki
  button chat ma haina, dropdown bata setting ma laga" — and the
  register UI stays English ("language chai English nai"); chat
  language mirroring untouched. Separately, "tala kina": the Owner
  panel's Close the day was buried under the feed/loyalty/settings/
  Reports sections — it now sits in its own block directly under the
  "Owner — today" summary. Daily actions on top, settings below.
- The dropdown's six items all ride existing paths. Batch close shows
  the live unbatched card segment (new read endpoint GET ?batch — the
  segment exactly as the chat stages it) and seals through the Gol
  brain itself ({message:'Batch close'} then {confirm:true} with the
  device session), so the admin-PIN gate applies unchanged: an
  unelevated tap gets the server's PIN demand rendered in the menu
  plus a door into the chat; a card staged in chat shows in the menu
  as "Staged — Confirm seal" (same ?pending store). Day close runs
  api.php close_day behind the arm-then-run tap-confirm; the toast
  carries the batches + unbatched remainder from the close record.
  Reprint renders today's most recent sale (new GET ?last_sale)
  through the same showReceipt() the tender path uses. Printer is the
  settings store's auto_open via the panel's own POST {settings}.
  Tax exempt is per-sale only: cart tax $0.00 behind an EXEMPT badge,
  sale POST carries tax_exempt and the server records tax 0 (record
  flagged), flag resets on completion or Clear, never persisted.
  Store open/closed is a new register_open setting: closed flips the
  header pill (red dot, "Register closed") and blocks new sales at the
  tap and at the sale endpoint — 422 "Store is closed — open it from
  Settings" — while chat, browsing and the panel keep working.
- Owner panel relocation: #ownerCloseDay (button + result line) sits
  between #ownerBody and #ownerLoyalty; renderDayClose() reads the
  ?report close record so the button reads "Re-close today" with the
  frozen summary beside it once the day is closed. Reports no longer
  renders the button (repClose/manualClose removed); the close path
  itself (golpos_close_day via POST {close_day:true}) is unchanged.
- Verified on a throwaway copy (php -S + python battery, 46/46): fresh
  ?last_sale null; printer toggle persists in settings.json across
  fresh reads; sale math exact (card 2×Coke $4.38 + $0.35 = $4.73;
  tax-exempt cash total == subtotal $3.49, flag on the record); batch
  segment 2 card sales / $7.10 with the cash sale excluded; the
  dropdown's brain calls demand the PIN with zero figures, stage the
  card after PIN creation (2 / $7.10) and seal Batch #1 with exactly
  the staged sale ids; a fresh unelevated session stays gated; day
  close record gross $10.59 across 3 sales composing Batch #1 with
  unbatched 0, read back through ?report; closed store refuses the
  sale POST (422 + the plain note) and re-opening restores it ($2.37);
  ?last_sale returns the newest sale with its lines; static checks
  pin the panel order (summary < close block < loyalty < settings <
  reports), exactly one dayCloseBtn, header order Settings < Owner <
  Talk to Gol, and repClose fully gone. A follow-up pass staged a
  second segment (2 / $4.74) and sealed Batch #2 from the menu's
  ?pending state. node --check and php -l clean. Asset stamps bumped
  to 36f30c5.


## Day 3 (Oct 28) — Owner panel redesign: cards, not a form wall (3c0998c)

- Owner rejection of the panel's raw full-width form wall ("hawa UI")
  answered presentation-only: the panel is now a plain column of cards
  in the register's tile recipe (tile bg/border/radius/shadow vars, so
  Midnight, Clean and Bold all carry it), under a title row — "Owner" +
  the store name (#ownerStoreName mirrors the header pill).
- Today card: the summary renders as four stat tiles (Gross · Cash ·
  Card · Sales, big --num figures in --price) with the staged-card line
  as a muted note under them; #ownerCloseDay stays directly under the
  summary, inside the same card. Demo feed moved to its own card
  (new #ownerFeed container in index.html; the owner handler renders
  the feed there instead of appending it to the summary). Receipt
  Loyalty is its own card; empty states ("No staged cards waiting",
  "No loyalty receipts yet", empty feed) are quiet .owner-muted lines
  inside their cards.
- Print settings card: .os-grid is now 3 columns (2 at ≤900px, 1 at
  ≤560px), labels above fields in the register's field language (time
  inputs styled too — they were bare before), checkbox rows as pill
  rows with accent-colored boxes; Save stays brand-gradient.
  Reports card: period chips now mirror the category chips exactly
  (gradient .on state), the date input in field dress, rows keep the
  dashed separators with blue tabular figures.
- Zero behavior change: every panel id and JS hook preserved
  (app.js edits are rendered-markup strings only — tiles, card titles,
  muted classes, the feed's new container). No server changes.
- Verified on a throwaway copy (php -S + a Node harness running the
  real app.js under a stub DOM against the real API): 18/18 runtime
  checks — panel opens with the store name in the title row; tiles
  carry the live figures of two rung sales ($8.50 gross · $3.77 cash ·
  $4.73 card · 2 sales); feed and loyalty empty states render muted in
  their own cards; all 14 settings fields + save render; a footer
  change saves and reads back from the settings store; the week chip
  switches the report and totals $8.50; Close the day arms, confirms,
  writes the close record ($8.50) and re-renders as "Re-close today"
  with the frozen summary; theme switch cycles midnight/clean/bold
  cleanly. Static pass: every preserved id exactly once, panel order
  head < today < close < feed < loyalty < settings < reports, every
  CSS var the new rules consume defined in all three theme blocks
  (--num via :root), ≤900px two-column and ≤560px one-column stacking.
  node --check clean. Asset stamps bumped to 3c0998c.

## Day 3 (Oct 28) — Manual amount entry: the Keypad tab + per-line tax (6c764cd)
- Owner ask: how does the cashier ring a MANUAL amount — taxable or
  non-tax? It did not exist. Owner design call: not a cart popover —
  the products panel gets a TAB SWITCH ("Products" | "Keypad") and
  Keypad mode turns the whole left side into a register keypad.
- The Keypad (web/index.html #keypadPane, .kp-* dress in the register's
  tile language, all three themes): an inset LCD readout (big tabular
  amount), large tactile keys 1–9, 0, ".", backspace, and a round C
  (clear) beside the readout; below, two large actions — "Add ·
  TAXABLE" (brand gradient) and "Add · NON-TAX" (field dress).
  Category chips live inside the Products pane, so they exist only in
  Products mode. Adding clears the amount and leaves the pad ready;
  the cart stays on the right and the line lands there with a TAX /
  NO-TAX tag (tooltip: remove and re-add to change the amount — qty
  is fixed at 1, removal rides the cart's line-remove wiring). The
  cart's ghost "Manual $" button only flips the tab — no popover UI.
  A physical keyboard drives the pad while the tab is up (digits,
  ".", Backspace, Enter = Add · TAXABLE) unless a field has focus.
  Entry discipline: integer part caps at $9,999, two decimals max;
  zero/empty refuses in the pad with a plain line.
- Tax is PER-LINE now, client and server: the 8% rate falls on taxable
  lines only. Catalog products stay taxable by default (a catalog
  taxable:false flag is honored if present; the seed is untouched),
  manual lines carry the keypad choice, and the Settings dropdown's
  whole-sale Tax Exempt still overrides everything to $0 and composes
  with manual lines (recorded tax_exempt as before).
- Server (api.php sale path): a line {manual:true, price, qty:1,
  taxable} needs no SKU and records exactly as keyed —
  {"manual":true,"name":"Manual amount","price":10,"qty":1,
  "taxable":true,"line_total":10}. The amount must be $0.01–$9,999.99;
  a bad amount (0, negative, over cap, non-numeric) refuses the whole
  sale with a 422 and the plain error, and nothing records. The
  receipt prints the manual line with its marker — "Manual amount x1
  $10.00 (NO-TAX)". Day totals and the Gol chat read the same sale
  records, so a manual sale counts identically in both (chat "today's
  total" answered $10.80 over the manual-only sale in the battery).
  No chat intents added; access/PIN/batch/loyalty untouched.
- Verified on throwaway copies (php -S + curl, and the real app.js
  under a Node stub DOM against the real API): server 20/20 — manual
  taxable $10.00 → tax $0.80 / total $10.80 / change $9.20, summary +
  chat totals include it; non-tax $10.00 → $0.00 / $10.00; mixed Coke
  $2.19 + non-tax $5.00 → $7.19 / $0.18 / $7.37; Beat-1 regression
  exact ($6.67 / $0.53 / $7.20, $10 tender → $2.80 change); five
  invalid amounts 422 with nothing recorded; Tax Exempt + manual
  taxable → tax $0.00 with the flag stored. Client 28/28 — tab flips
  both ways with the cart intact, chips inside the hidden Products
  pane in Keypad mode, wired key buttons drive the pad, amounts clear
  after Add, entries survive a tab round-trip, the × removal restores
  catalog-only totals ($2.19 / $0.18 / $2.37), cap/backspace/clear
  discipline, manual-only tender records through the real API and the
  receipt shows the marker. node --check / php -l clean. Asset
  stamps bumped to 6c764cd.
- Living shelf (owner directive, Oct 28: "product lai live inventory
  banau, inventory count sahit alive dekhiyo — filled bar lights,
  almost empty — Coca-Cola a liquid bar, candy solid bar" + "item ko
  image hala"): every product tile is now a shelf slot. The seed
  carries stock / par / img per product (par by category: Drinks 24,
  Snacks 20, Grocery 12, Household 10, Health 15, Beer and Wine 24,
  Counter 30; the opening shelf shows every state, Hot Dog at 0).
  Tiles show the packshot (62 real product images normalized to
  480x480 white-padded WebP in web/assets/products/, 1.1 MB total;
  a missing file falls back to the letter tile via onerror — never a
  broken-image icon), the live count ("24 in stock" / "Only 3 left"),
  a state light (healthy calm, <50% amber, <20% red pulse, 0 = OUT
  tag + dimmed tile) and a fill bar against par: a LIQUID bar with an
  animated foam wave for Drinks + Beer and Wine, a SOLID bar with a
  slow shine for snacks/candy/grocery/household/health/counter.
  LIVE means live: api.php decrements the standalone shelf as sales
  record (store.php golpos_decrement_stock, floors at zero, untracked
  products skipped; link mode untouched — MR.STORE moves its own
  stock, never both), and golpos_catalog_fill_shelf() fills
  stock/par/img from the seed into live catalogs written before the
  shelf existed, without rolling back staged price changes. The grid
  refreshes after every tender and on chat-drawer close, and bars
  animate from their last level (last-fill tracking) instead of
  snapping. Tapping an OUT tile toasts "Out of stock" and adds
  nothing; the chat's add path is unchanged (viaChat). Rider from the
  owner's screenshot: the tax-exempt flag now disarms itself whenever
  renderCart finds the cart empty — the EXEMPT badge never lingers
  on an empty cart, and the Settings toggle re-reads the same flag.
- Verified on a throwaway copy (php -S + curl, and the real app.js
  under a Node stub DOM against the real API): API — 2x Coke sale
  exact ($4.38 / $0.35 / $4.73, change $5.27) and Coke stock 18 -> 16
  in the payload; Beat-1 exact ($6.67 / $0.53 / $7.20, $2.80 change);
  manual taxable $10 -> $0.80 / $10.80, non-tax $10 -> $10.00,
  exempt + manual taxable -> tax $0.00 with the flag stored; an
  OUT product's sale floors its stock at 0, never negative. Client
  21/21 — tile states render from the payload (Coke liquid ok 75%,
  Gum solid crit "Only 3 left", Eggs crit, Milk solid low, Heineken
  ok at exactly 50%, Hot Dog OUT dimmed + tag), OUT tap refused with
  the toast, tender drops the Coke count to 16 with the bar target
  updated and server stock agreeing, a server-side stock write +
  drawer-close refresh raises the bar again, the exempt rider
  disarms on last-line and last-manual-line removal, keypad totals
  (10 TAX + 10 NON-TAX -> $20.00 / $0.80 / $20.80) and Beat-1 client
  totals exact. All 62 packshots serve over HTTP as image/webp.
  node --check / php -l clean. Asset stamps bumped to 5e2e56f.

## Day 3 (Oct 28) — Number MEANING fix: price, count, or part of a name (dc1c5e1)

- Owner-caught live on gol-pos-dev: "can u change the price of coca cola
  20 oz to $3" staged **$20.00** — the extractor read the FIRST number in
  the sentence, and the first number was the product's own size. The
  staged card was cleared and the catalog verified untouched ($2.19)
  before the fix began. The fix is one shared meaning layer in
  server/brain.php, not a patch on the phrase:
  - `golpos_extract_price` — the matched product is consumed first and
    size-glued numbers (20 oz, 12oz, 16.9oz, 4pk, 500ml) are masked
    before any number is read. An explicit $ amount always wins; a $
    amount marked as the FROM price ("bata/from") is excluded; without
    $, the price is the number bound to a price word (after
    to/at/price/mulya/dam/daam, or before the postposition "ma") or the
    one candidate left standing; a classifier-bound number ("20 ota")
    is a count, never money. Two live candidates or none → the brain
    ASKS ("Which price — $3 or $4?") and stages nothing.
  - `golpos_extract_count` — counts bind to classifiers (ota/vata/वटा/
    pcs), receive verbs, or the item word; money and sizes are masked
    out. It replaces the receive lane's last-number-standing read
    ("12 ota lays ayo, $1.19 ma" now receives 12; the money never
    touches the card) and gives add-to-cart its quantity ("2 ota coke"
    → qty 2; "coke 20oz thap" → 1 — a size is not a quantity; app.js
    honors action.qty, landed with 5e2e56f).
  - Add lane: a price in the sentence makes it a cataloging sentence —
    unless the name said is exactly a known item's own name/alias, the
    item is NEW. "add sunrise energy drink 12oz at $3.49" stages the
    new-item card at $3.49 (name keeps its 12oz; Red Bull and Monster
    both alias "energy drink" and neither is staged or rung), and "add
    AAA batteries 4pk $5" is a new $5 item, not the $5.99 AA pack.
    New-item names are built token-wise by `golpos_item_name_from`:
    command words and the one price token come out, sizes stay.
  - Price-change lane also accepts the "price of X to $Y" construction
    (a $ or a decimal after "to" — a bare "to 2" stays a query).
  - `golpos_size_pick` (server/store.php, inside resolve_item): a size
    the owner named picks between siblings — on the QA catalog "coke
    20 ota ayo" lands on Coke 20oz (mr-23), not the Coca-Cola 12pk that
    owns the bare "coke" alias. Exactly one size carrier + a shared
    non-size name word, or no pick — a price or count can't impersonate
    a size (verified: the $2.50 in "Coke price 2.50 gara" picks nothing).
  - Only brain.php + store.php are in dc1c5e1; the same-day working tree
    also carried the living-shelf build's uncommitted hunks (a store.php
    receive path, the Keypad-pane rows) — those were left uncommitted
    for their own build, untouched.
- Verified on throwaway copies only (nothing deployed): trap battery
  21/21 — the owner's exact sentence stages $3.00; glued/decimal sizes,
  the bata FROM-price, ambiguity → ask with zero staged, no-amount →
  ask; new-item cases at $3.49/$5.00 with sizes kept in the names;
  counts 2/1/1; and the Beat-3 choreography end-to-end (stage $2.50 →
  "gol" unelevated demands PIN creation → PIN executes to exactly
  $2.50 → restored $2.19). Gate-2 subsets 39/39 structural (the 3
  Devanagari price-query language flags are the deterministic lane's
  language-neutral name+price line — byte-identical on pristine HEAD,
  not a regression). Non-regression on the original harnesses: access
  43/43, auto-batch 16/16, Nepali gate 17/17. Link-mode receive battery
  on a QA-bound server throwaway (deleted after): 9/9 — receive count
  20 on Coke 20oz with no price field on the card, the mixed
  count+price case at qty 12, and a live stock-read control (1955);
  cards were never confirmed, so MR.STORE stock never moved. The
  committed tree was then re-proven standalone (trap + access) from a
  clean archive of dc1c5e1. php -l / node --check clean. No stamp bump:
  the only client asset in the fix (app.js qty) already shipped inside
  5e2e56f and index.html already points at it; PHP serves uncached.

## Day 3 (Oct 28) — Keypad-pane product list: the shelf beside the pad (ff6a208)
- Keypad-pane product list (owner directive, Oct 28: "yo khali bich ma
  products list dami prama" — the pad sat in the left of its pane with
  empty white beside it): the pane is now two columns, the pad at its
  full size (track capped at 520px, keys byte-identical) and the live
  product list filling the remaining width. At the existing 1100px
  layout breakpoint the pane stacks — pad first, list below with a
  bounded 400px internal scroll; no fixed pixel widths anywhere in
  the list column, so nothing can overflow horizontally.
- Rows are the living shelf in miniature, not a second inventory:
  compact category chips (the pane's own filter, list only) over
  tappable rows — packshot thumb, name, price, state dot + count, and
  a thin 4px fill bar. One catalog, one stock model: the rows read
  the same stockOf / parOf / stockState / isLiquid helpers as the
  tiles, the fill animation was factored out of renderGrid into a
  shared animateFills (one lastFill tracker serves both
  presentations), a row tap runs the very same addToCart (OUT rows
  dim and refuse with the "Out of stock" toast), and the list
  re-renders on the same loadCatalog triggers as the grid (after
  tender, on chat-drawer close, on staged-card confirms). The
  keypad's own behavior — entry discipline, Add TAXABLE / NON-TAX,
  physical keyboard — is untouched, and the Products tab is exactly
  as it was.
- Verified on a pristine archive of the build (real app.js under a
  stub DOM whose querySelectorAll parses the rendered rows, against
  the real PHP API): battery 41/41 — all 62 rows with signals
  identical to the tiles for every sampled state (Coke liquid 75%,
  Gum crit, Milk low, Eggs crit, Heineken liquid at exactly 50%,
  Hot Dog OUT); row tap adds Coke at $2.19 with cart totals
  2.19/0.18/2.37; OUT row refuses; after a 2× Coke card tender the
  grid, the list and the server all agree on 16; the Drinks chip
  filters the list to its 14 rows and leaves the grid untouched;
  keypad regression (manual taxable $10 → 10/0.80/10.80) and Beat-1
  (6.67/0.53/7.20) exact; layout asserted from the shipped CSS
  (two-column template, 1100px stacking, keys unshrunk, list
  overflow-y auto, zero fixed widths). node --check clean.
  Nothing deployed.
- Landed inside ff6a208: the Add Stock build committed while both
  builds were uncommitted in the same three web files, so its commit
  carries these hunks too (verified present in ff6a208's tree).
  This entry registers the list build and bumps the asset stamps
  to ff6a208, the commit whose tree contains it.

## Day 3 (Oct 28) — Add Stock from the tile: the receive sheet (ff6a208)
- Owner, watching the living shelf live: "stock add nai garne bato pani
  dine??" Every tile now wears a quiet "+ Stock" button in its foot
  (price left, button right); on an OUT tile the same button wears the
  brand dress — it is the rescue. Tiles became divs with role/tabindex
  and Enter/Space parity (a <button> cannot nest the stock button).
- The sheet mirrors the cash panel: product image + name, "Now: N in
  stock", a - / + stepper, +6 / +12 / +24 chips (they set the amount),
  a typed custom amount, and "Add N to stock". Nothing else on it.
- ONE receive path, never a second writer: golpos_receive_stock in
  store.php. Standing alone it increments the live catalog — the same
  store golpos_decrement_stock decrements, so receives and rung sales
  compose on one number (an untracked product starts tracking at the
  delivery). In link mode it calls mrstore_adjust_stock — the exact
  function the Gol chat's stock_receive card executes — and logs the
  movement beside the chat's receives with via: "screen".
- api.php POST {stock_add:{sku,qty}, session}: quantity is a whole
  number 1..9999 or the call is refused plainly (422, nothing written);
  unknown SKU likewise. The endpoint reads the SHARED session store
  only — it never verifies a PIN itself.
- The gate mirrors the chat exactly: receiving is an admin-domain
  execution (the merged brain PIN-gates stock_receive the same way).
  An unelevated session gets {error:"pin_required", pin:"create"|
  "verify"|"locked"} and nothing is written. The sheet then asks the
  PIN INSIDE itself, but the digits run through brain.php with the
  chat's own session messages (arm with the bare admin ask, then the
  PIN as a message) — so the two-entry create flow, wrong-PIN attempts
  and the 3-strike 10-minute lockout are the brain's own machinery,
  and an already-elevated session is never asked again. The sheet
  retries the receive after each PIN entry and trusts the server's
  answer, not the reply's wording; cancelling the PIN step sends the
  chat's cancel word so the armed wait never eats the next chat
  message. On success the sheet closes, the catalog reloads, the
  tile's bar pours UP to the new level (last-fill animation; counts
  may exceed par — the bar caps at 100%, the number stays true), and
  a toast speaks it: "Coca-Cola 20oz — stock now 30."
- Verified on throwaways (php -S + curl, and the real app.js under a
  Node stub DOM against the real API): server 31/31 — fresh session
  is pin-gated at "create" with zero written; the brain create flow
  (including a deliberate confirm mismatch) then lands +12 on Coke
  18 -> 30 with payload and catalog file agreeing; elevated +6 -> 36
  with no ask; a second session eats 3 wrong PINs into the lockout
  (pin:"locked", minutes named, nothing written) while a third
  elevates after one wrong try; Hot Dog 0 -> +24 -> 24; quantities
  0 / -3 / 10000 / "abc" / 12.5 and an unknown SKU all refuse 422
  with stock untouched; a card sale takes Coke 36 -> 34 and a
  receive composes back to 40; Beat-1 exact ($6.67 / $0.53 / $7.20,
  $2.80 change); chat price answer and no-phantom-pending intact.
  Client 23/23 — the door renders on every tile (rescue dress on the
  OUT tile), the sheet's full create-PIN choreography lands the
  receive and updates the tile to 30, the elevated receive skips the
  PIN step entirely, qty 0 refuses in-sheet, the Hot Dog rescue
  undims the tile and drops the OUT tag, Beat-1 client totals and
  the Keypad regression exact. Link mode proven at code-path level:
  with the link ON and the bridge unreachable the endpoint routes to
  mrstore_adjust_stock, refuses plainly, and writes NOTHING locally
  (the live QA receive is the parent's deploy-step proof). One
  honesty note: ff6a208 also carried the Add Product sheet's
  then-uncommitted tree work (its own entry is the 2f69505 one); the
  combined tree was battery-green here and product_create was
  smoke-proven on the same throwaway (SKU 1063 created, catalog 63).
  node --check / php -l clean. Asset stamps read ff6a208 (bumped in
  2f69505 — the commit's client assets are the ones the stamps name).

## Day 3 (Oct 28) — Add Product on the register: the full catalog form (ff6a208 + bridge commit)
- Owner: "mathi add products button pani haldeu — category haru
  dherai, price margin dherai option huncha, jun MR.STORE ko catalog
  le ni khulcha." A "＋ Add Product" button now sits at the top of
  the Products pane (right end of the Products | Keypad tab row, all
  themes) and opens a sheet in register dress: Name · Category (the
  full live list from the catalog payload, plus "＋ New category…"
  free text that creates it) · Price · Cost (optional) · Margin,
  live both ways · Barcode (optional) · Opening stock + Par ·
  Taxable (default on) · Image (optional; blank = the shelf's
  letter-tile fallback).
- Margin is one number seen two ways: typing price + cost fills the
  margin % ((price − cost) / price); typing a margin % with a cost
  re-prices to the cent (cost / (1 − m)). The record stores cost when
  given; margin stays derived, the shape MR.STORE's catalog reads.
- ONE constructor: golpos_create_product (server/store.php) builds
  the record exactly as the brain's new-item executor builds it —
  same field order, same SKU scheme (1000 + count + 1, stepped past
  collisions; a blank barcode becomes the generated SKU) — then the
  shelf fields ride along (stock always, par when > 0, taxable:false
  only when off, img only when set). Duplicate barcode (against any
  sku or barcode) refuses by name; bad name/price/cost refuses
  plainly and writes nothing. Brain.php was off-limits to this
  build, so the shared construction is mirrored in store.php, not
  extracted from the brain.
- Link mode creates through the bridge's own machinery:
  mrstore_create_product (server/mrstore.php, this commit) appends
  via mrstore_load_catalog / mrstore_save_catalog in the sync's
  new-item shape and patches the new product's stock override —
  behind the same QA-store fence as every bridge write; a refused
  or failed bridge create writes nothing locally and the sheet says
  so plainly.
- Creation is admin-domain, gated in the sheet: golpos_sheet_pin
  shares the chat's session record — same elevation, same attempt
  counter, same 3-strike / 10-minute lockout. Unelevated, the sheet
  swaps to an inline PIN step (create-then-confirm when no PIN
  exists, staged under sheet-private keys so a half-finished sheet
  flow never hijacks a chat message; verify when one does); already
  elevated, no ask. (The Add Stock sheet drives the brain itself;
  the two gates present identically to the owner.)
- Verified on a throwaway copy only (nothing deployed): Phase A —
  the real app.js under a stub DOM against the real PHP API, 26/26:
  margin 39.8% from $3.99/$2.40 and $4.00 re-priced from $2.00 at
  50%; the PIN create dance writes nothing until the second entry;
  "Sunrise Trail Mix 4oz" lands as SKU/barcode 1063 with cost 2.40,
  stock 12, par 12; an elevated margin-driven create saves $4.00; a
  new "Seasonal" category appears in the chips and the grid lands
  on its tile; scanning 1063 rings the product at $3.99. Phase B+C
  — server flows, 29/29: verify flow with the tries countdown, the
  lockout holding even the right PIN out, the invalid set (empty
  name, price 0, negative price, negative cost, duplicate barcodes
  naming Coca-Cola 20oz and the trail mix) writing nothing, a
  taxable-off product selling at $0 tax, the trail mix's stock
  12 → 11 through a rung sale, the chat answering its price by its
  full name ($3.99), Beat-1 exact ($6.67/$0.53/$7.20/$2.80), keypad
  totals ($10 → $10.80 taxable / $10.00 non-tax), the shelf payload
  intact, and the link fence: link ON for a non-QA store refuses
  the create and writes nothing. One observation for the resolver's
  owners: the short form "trail mix" resolves to the seed's Trail
  Mix 2oz — its alias "trail mix" duplicates its name tokens and
  resolve_item counts each occurrence (4 vs the created product's
  3); the full name resolves correctly. Pre-existing scoring,
  untouched here.
- Landed inside ff6a208: this build ran concurrently with the Add
  Stock and Keypad-list builds in the same web files, and its
  app.js / index.html / styles.css / store.php / api.php hunks were
  committed in ff6a208 (its tree is what the stamps name — index.html
  already reads ?v=ff6a208, no bump needed). This commit adds only
  the bridge create function and this entry. php -l / node --check
  clean. Nothing deployed.

## Day 3 (Oct 28) — Barcode doors: one barcode, three ways in (47dbe41)
- Owner: "gol ma direct barcode haldida pugcha" — in Gol, just
  putting the barcode directly is enough — and "add products le
  register ko scanner bata barcode liyera pani add garna sakcha":
  the Add Product sheet can also take the code from the scanner.
  One barcode now enters through three doors.
- Door 1 — the chat sentence (server/brain.php). A new
  golpos_extract_barcode joins the number-meaning layer: a labeled
  run ("barcode 012345678905", "barcode: 0123...") or a bare run of
  8–14 digits is read FIRST in both lanes that can create an item
  (the explicit new-item lane and the add lane's cataloging paths)
  and blanked out of the working string, so price, count and name
  extraction never see it — this morning the same run priced an
  item at $12,345,678,905.00 and asked "which price?". Under 8
  digits is not a barcode; a run glued to a size unit is a name's
  size. The staged card carries the code (and the chat card renders
  a "Barcode …" line), execution writes it onto the product, and a
  code another product already carries — as its barcode or its
  sku, the golpos_create_product test — refuses the add outright
  with that path's own words ("That barcode is already on
  Coca-Cola 20oz."), at stage time and again at confirm time in
  case the code was taken in between. Saying the holder's own code
  with its name ("add coke 049000000443") simply rings the item.
- Rider in the same layer: golpos_item_name_from never dropped a
  punctuated price token — "$3.99" cleans to "399", "3.25" to
  "325", neither equals the price, so every chat-added item kept
  the remnant in its name ("Mango Lassi 325"). The drop test now
  reads the raw token as a pure number (letters make it a size,
  never the price); names come out clean ("Mango Lassi").
- Door 2 — the scanner into the form (web/app.js). The Add Product
  sheet's barcode field is scanner-ready: a hardware scanner is a
  keyboard wedge and ends its burst with Enter, and that Enter is
  now swallowed in the field (preventDefault; focus moves to
  Opening stock) — never a submit, never a create. Nothing else
  about the sheet changed. openProductSheet takes an optional
  prefill string; the button's click handler passes an event, so
  only a real string pre-fills (the battery caught the event
  landing in the field as "[object Object]" — guarded).
- Door 3 — scan-unknown at the register (web/app.js). A scan or
  scan-entry lookup that finds nothing now splits by shape: a
  genuine barcode shape (8–14 digits) raises a toast offer — "Not
  in the catalog — <code>" + an "Add it?" button — that opens the
  Add Product sheet with the code pre-filled (creation still
  PIN-gates inside the sheet, untouched); a short code or a name
  miss keeps today's plain not-found word in chat. One small CSS
  rule dresses the toast's button.
- Verified on throwaway copies only (nothing deployed): PHP
  battery on the real server, 38/38 — labeled + bare codes land
  on the card and on the created product; the no-$ sentence reads
  its price from the plain figure, never the run; a 5-digit run
  is ignored as a barcode; both lanes refuse a taken code naming
  Coca-Cola 20oz and stage nothing; the executor refuses a code
  taken between stage and confirm, naming the form-created
  holder, exactly one holder standing; the number-meaning traps
  stay green (owner sentence → $3.00, size-not-money, bata/from,
  same-price, ask-on-missing, ask-on-two, classifier count) and
  Beat-1 through the API is exact (6.67/0.53/7.20, $2.80 change).
  JS battery — the real app.js under a parsing stub DOM against
  the real API, 15/15: Beat-1 totals, shelf fills, keypad-pane
  rows, keypad manual $10 → 10/0.80/10.80; the unknown-scan offer
  opens the sheet pre-filled; short-code and name misses show no
  offer; a simulated scanner burst fills the barcode field, its
  Enter is swallowed, focus lands on stock, no create fires; the
  staged card shows its barcode line and a barcode-less card
  doesn't. php -l / node --check clean. Nothing deployed.

## Day 3 (Oct 28) — Transactions history: the reprint door grows a memory (511d287)

- Owner's ask: the Settings dropdown's reprint must show the
  transaction history with item names. Reprint Last remains as the
  one-tap shortcut; beside it now sits **Transactions**, opening a
  history sheet in register dress over the till's own sales log.
- Data path (server/api.php): new **GET ?history** — read-only,
  sales grouped by day (newest day first, newest sale first inside
  the day), each day capped at the latest 100 with its true count
  kept and a truncated flag when capped. Records ride back exactly
  as recorded — lines with names, manual flags, tax_exempt,
  tendered and change — because the panel renders and reprints
  from this one read. Standalone and link mode read the same till
  log; MR.STORE's journal is the platform mirror, not the source.
- The sheet: each row is the sale at a glance (time, receipt no.,
  tender, item count, total, EXEMPT marker where flagged); a tap
  expands it inline to the line items — names × qty + line totals,
  manual lines keeping their (TAX)/(NO-TAX) marking — plus
  subtotal / tax / total, paid-by, and cash given + change. Each
  expanded sale carries **Reprint this receipt**, which renders
  THAT sale through the same showReceipt() the tender path and
  Reprint Last use — no second receipt renderer — and reprinting
  writes nothing back to the log. Empty and unavailable states
  read plainly. Access posture unchanged: the panel lives behind
  the same Settings dropdown as Reprint Last, which is ungated —
  no new PIN gate for a read of receipts.
- MR.STORE ticket finding (verify-only, no MR.STORE code touched):
  on a link-mode throwaway with a stub bridge, mrstore_append_ticket
  hands MR.STORE's lm_pos_append_ticket a row of storeId, storeName,
  day, at, type, receiptId (the POS txn id), pay, total, cash, card,
  tax and **lines — each line {name, qty, price, line_total}, so the
  journal ticket DOES carry the item names**. Not on the ticket:
  subtotal, the tax_exempt flag, tendered/change_due, line SKUs,
  and a manual line's manual/taxable markers (it arrives as a plain
  line named "Manual amount"). The on-screen journal display on
  the real QA store is the parent's proof to run at deploy.
- Verified on throwaway copies only (nothing deployed): PHP
  battery over real HTTP, 18/18 — Beat-1 exact (6.67/0.53/7.20,
  $2.80 change), manual NO-TAX and TAXABLE records faithful,
  days grouped newest-first with counts 3/2/1, sales newest-first
  inside the day, exempt record's flag intact, ?history read-only
  (log md5 unchanged, a fresh log gains no file from the read),
  the 100/day cap (105 → 100 returned + truncated), empty log →
  {days: []}, ?last_sale / ?summary / shelf decrement (Coke
  18 → 16) / both PIN gates unregressed. JS battery — the real
  app.js under a stub DOM fed the real ?history payload, 29/29 —
  Beat-1 + keypad cart totals exact, three day groups with the
  right labels, six rows newest-first with tender + item counts,
  expansions showing exact names/qty/line totals, the NO-TAX and
  EXEMPT markings, tendered/change, collapse on second tap, the
  reprint rendering the CHOSEN sale (not the last), Reprint Last
  still rendering the newest, empty + failure states plain.
  php -l / node --check clean. Nothing deployed.

## Day 3 (Oct 28) — Bill scan → catalog: the bill adds and updates products (d47a10f)

- Owner's order: "gol le bill scan bata new products add and update
  garihalcha" — from a vendor bill scan, Gol adds NEW products and
  updates existing ones, right away. The POS had no bill lane of its
  own (the parity map carries MR.STORE's as the model); this build
  lays the lane on the writers that already exist — no second create,
  no second receive.
- The read (server/meta.php): **golpos_meta_read_bill** — Spark
  vision-reads the bill photo into vendor + lines {name, qty,
  unit_cost, barcode}, following the receipt reader's honesty rules:
  per-unit costs (an extended amount is divided by its quantity), a
  code counts only at barcode length (8–14 digits, the barcode
  doors' discipline), anything unreadable comes back null and is
  never reconstructed. A bill prints COSTS, never shelf prices.
- The door (server/photo.php): a photo the owner marks as a bill
  (prompt "bill" / "invoice", or mode=bill from the client) takes
  the bill branch instead of SAM; the chat session rides the upload
  so the price wait waits in the session the owner answers in. A
  failed read saves the photo and says so — nothing staged.
- Matching + staging (server/store.php, **golpos_bill_process** —
  the post-vision seam, callable without a photo): each line is
  matched by exact name/alias key, then by the shared resolver; a
  barcode never matches a line UP into the catalog. Duplicate lines
  merge. All-matched bills stage at once as ONE 'bill' card —
  today's behavior. Any unmatched line makes the bill a
  new-product proposal, and a bill NEVER stages half-ready: the
  whole read waits in the session (**bill_await**, the existing
  session store — no parallel store), and Gol's reply lists each
  new item with its bill cost and asks for shelf prices.
- The price wait (server/brain.php): while bill_await lives and no
  card is staged, the owner's next answers belong to the bill
  (golpos_bill_apply_prices). Per-item answers ("dried mango slices
  3.99") price by name against the unpriced new lines only, using
  the shared meaning extractors; one margin ("sabai ma 40%
  margin") prices every unpriced line from its cost, rounded to
  cents — a line whose cost never read stays unpriced rather than
  invented. Partial answers are remembered; the re-ask names only
  what is still missing. "cancel" drops the bill and writes
  nothing. With prices complete, the full card stages: purchase
  lines + receives + creates, one "gol" lands everything.
  Execution of a 'bill' card is PIN-gated at the confirm word,
  in the same list as stock_receive / new_item.
- Execution (**golpos_execute_bill**): creates land first via
  golpos_create_product (so receives can land), the purchase is
  recorded once via **golpos_record_purchase** (data/purchases.json;
  each line marked with what actually landed; link mode also rides
  the link log), every line's stock arrives via
  golpos_receive_stock — the one receive path — and a matched
  line's unit cost refreshes the product's stored `cost` when the
  record carries one (standalone catalog; in link mode MR.STORE
  keeps its own cost from its own bill lane, and creates/receives
  route through mrstore_create_product / the bridge receive, as
  the screen flows do). A create that refuses — a barcode another
  product already holds — is reported by name in the execution
  reply while the rest of the bill lands; the reply is composed
  from the executor's report, never from the card's hopes.
- The card (web/app.js + styles.css): the bill card lists matched
  lines as qty × cost; NEW lines carry a green NEW badge with the
  owner's shelf price beside the bill's cost — the two numbers
  never blur — plus the barcode when the bill printed one, and the
  bill photo itself.
- Verified on throwaway copies only (nothing deployed): PHP
  battery, 52/52 — the post-vision seam driven directly with
  canned reads + the brain/API over real HTTP: matcher
  preconditions (bill-caps name matches; four genuinely-new names
  read as new); (a) 2 matched + 1 new → ask-for-price reply lists
  the new item + cost, nothing staged, the read waiting in the
  session; (b) "sabai ma 40% margin" → card stages complete at
  $3.36 on a $2.40 cost, total $59.40, "gol" from an unelevated
  session executes nothing until the PIN create dance replays it —
  then the purchase records once, matched stock lands with costs
  refreshed (Coke 1.50 → 1.55, Lays 0.60 → 0.65), and the new
  product exists with price / cost / stock / barcode; (c) the
  per-item price path lands at exactly the owner's $3.99; (d) an
  all-matched bill stages immediately with no ask; (e) a new line
  whose barcode Pepsi already holds refuses by name in the
  execution report while the rest of the bill lands and the
  purchase record marks that line landed=false; (f) cancel at the
  ask stage writes nothing. Photo endpoint over HTTP: bill mode
  with no vision key degrades honestly (photo saved, no read,
  nothing staged), the SAM path untouched. Non-regression: Beat-1
  exact (6.67/0.53/7.20, $2.80 change), the owner price trap
  stages $3.00, the access battery's report-ask still earns the
  PIN demand with no figures. JS battery — the real chatCard
  under a stub DOM, 8/8: NEW badge only on new lines, shelf price
  beside cost, barcode shown, bill photo on the card, the
  price_change card unregressed. php -l / node --check clean.
  Nothing deployed.
- One resolver observation carried forward, not fixed here (the
  Add Product build noted it first): resolve_item's token scoring
  counts a token once per occurrence in name+aliases, so an alias
  duplicating a name word double-counts ("Mountain Mist Soda
  12oz" scored onto Mountain Dew on 'mountain' ×2 before tying
  out). Bill matching inherits the resolver as designed — the
  staged card always shows the catalog name it matched, so a
  mis-match is visible before "gol", never silent — but the
  resolver's owners should dedupe its token multiset.

## Day 3 (Oct 28) — Register money polish: the list scrolls, the pad counts cents, cards can carry a surcharge (8e7ec18)

- Three owner directives from live testing, one pass. (1) The
  Keypad pane's product list stretched the page instead of
  scrolling: the pane is now BOUNDED — height calc(100dvh −
  var(--cart-top) − 118px), the sticky-header offset each theme
  already carries, minus layout padding and the tab row — so the
  flex chain (.kp-side / .kp-list, min-height: 0 throughout) gives
  the list the pane's height and it scrolls inside its own column
  under a slim register-dress scrollbar (8px webkit thumb in
  --line, scrollbar-color to match). The pad never moves or
  shrinks; the Products grid pane is untouched; at ≤1100px the
  stacked pane un-bounds (height: auto) and the list keeps its
  bounded 400px scroll.
- (2) The Keypad took decimal typing — 2, 9, 9 read $299.00. The
  amount model is now an integer CENTS buffer, ATM-style: digits
  shift in as cents (2 → $0.02, 9 → $0.29, 9 → $2.99), the "." key
  is a "00" key (two zeros within the cap), ⌫ drops the last digit,
  C clears, and six digits cap the buffer at $9,999.99 — the
  server's manual-amount limit, so an over-cap amount cannot even
  be keyed. Physical keyboard follows the same model ("." ignored;
  Backspace/Enter as before). Add · TAXABLE / NON-TAX build the
  manual line from the buffer exactly as before; the cash-tender
  panel is a different component and is untouched.
- (3) Card surcharge: a new setting `card_surcharge_pct` (default
  0, validated 0–10 at one decimal) in the ONE settings store
  (golpos_settings_defaults + the save whitelist), edited from the
  Owner panel's settings card (percent input, microcopy "added to
  card sales only"; the field re-reads the stored value after save,
  so a rejected 11 never poses as set). The sale endpoint computes
  the surcharge ITSELF from the saved setting — round((subtotal +
  tax) × pct / 100, 2) — for card sales only, records `surcharge`
  (+ `surcharge_pct` for display) and folds it into the total, so
  the day summary's card figure is the paid figure. The client
  never sends a figure (a posted surcharge of 99 lands as the
  computed 0.22). While a card payment is being taken (the Card
  tap), the totals panel shows "Card surcharge (3%)" and the paid
  total; the receipt prints the line, the Transactions expansion
  shows it, and mrstore_append_ticket passes it into the journal
  ticket's own `surcharge` field (inside total/card as well, as
  the ticket's card field expects). Cash never carries it; at the
  default 0% the sale record is byte-identical to before.
- Verified on throwaway copies only (nothing deployed): PHP
  battery over real HTTP, 37/37 — setting default/save/read-back,
  11 and −1 rejected, 2.5 saves; Beat-1 card at 3% (6.67 + 0.53 →
  surcharge 0.22 → total 7.42) recorded with the surcharge; cash
  Beat-1 exact (7.20, $2.80 change, no surcharge key); client-sent
  surcharge ignored; a manual $2.99 line taxes 0.24 and surcharges
  on the taxed total; ?history round-trips the figure; the day
  summary's card total includes it; at 0% the card record has no
  surcharge key and the old totals; link mode on a stub bridge —
  the captured journal ticket carries surcharge 0.22 with card =
  total = 7.42 and named lines (cash ticket carries 0). JS
  battery — the real app.js under a stub DOM against the real
  server, 38/38 — the full cents-shift keypad set (2,9,9 → $2.99 →
  line tax 0.24 → total 3.23; 1,0,0,0 → $10.00 → $10.80; 5,"00" →
  $5.00; seven digits refused at the cap; ⌫ → $0.29; C → $0.00 and
  Add refused at zero; physical digits/Backspace, "." ignored);
  the card flow (row hidden before tender, the Card tap painting
  label + $0.22 + $7.42 before the sale lands, receipt and history
  expansion carrying the line); cash exact with no surcharge
  anywhere; grid + keypad-list rendering and both PIN gates
  unregressed. CSS assertions on the shipped sheet, 15/15 — the
  bounded chain, the scrollbar dress, no fixed pixel heights on
  the list column outside the breakpoint rule, keys unshrunk.
  php -l / node --check clean. Nothing deployed.
