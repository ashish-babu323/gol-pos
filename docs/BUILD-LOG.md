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
