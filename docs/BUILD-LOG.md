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

## Day 3 (Oct 28) — Voice + camera: Gol's ears and eyes on the register (070de6c)

- Owner directives Oct 28 09:51–09:54 (spec:
  ~/workspace/hackathon-meta-2026/register-watch-spec.md): mic =
  Gol's ears, chat = Gol's hands, camera = Gol's eyes — and the
  monitoring is OPEN, never covert. Five pieces, one pass; the
  continuous Register Watch itself (auto-flags for exchanges with
  no matching ring-up) stays its own later build — this pass
  ships the senses and the on-demand lanes it will feed.
- VOICE (press-to-talk only, never always-on): a 🎤 in the
  register header and a 🎤 in the chat form, both driving the
  browser's SpeechRecognition (webkit prefix handled). The header
  mic OPENS the drawer and starts listening; interim results fill
  the chat input live; the final transcript goes through
  voiceFinal() → sendChat() — the EXACT typed path, same session,
  same brain, so a spoken "gol add 3 dollars in coke" stages the
  same card a typed sentence stages. Listening dress: a pulsing
  mic + a "🎙 Listening… speak now" pill. Language follows the
  conversation — an honest client heuristic, documented here
  because the brain returns no language field: every sent chat
  (typed or spoken) re-teaches a per-device stored preference
  (Devanagari or the brain's own Romanized-Nepali stems → ne-NP,
  Hindi stems → hi-IN, else en-US; default en-US) and the
  recognizer opens in that language. Errors degrade to plain
  toasts (mic blocked / didn't catch that / voice not available
  in this browser) — the register never breaks because voice is
  unavailable.
- CAMERA BUTTON: a 📷 in the header, one tap from the register
  face. With getUserMedia it opens an in-app capture view (live
  video, Capture → preview → "Use as photo" / "Use as bill",
  through the existing sendPhoto() → photo.php lanes, bill vs
  receipt exactly as the existing modes decide); without it, the
  tap falls back to the drawer's native capture input — the same
  photo.php lane either way.
- SCAN-BY-PHONE: Settings → "Scan with phone" mints a pairing
  (new server/pair.php): a random 24-hex token bound to ONE
  register session, ~10-minute life, stored server-side
  (data/pair_tokens.json, pruned on read), shown as a big 6-char
  code + the full URL. The phone opens web/scan.html (typed code
  or the ?t= link), picks Bill or Photo, and uploads to the SAME
  photo.php with the token; photo.php resolves the token to the
  register's session (the register's session OWNS the flow — a
  session the phone claims is ignored) and pushes the lane's
  {reply, card} into that session's inbox (data/inbox.json, cap
  20). The register polls ?inbox every 4s (read-and-clear) and
  renders arrivals into its own chat with the drawer opened — a
  scanned bill's bill_await / staged card therefore complete in
  the register's chat session. Expired/unknown tokens refuse
  plainly and process nothing. No QR is rendered (no QR library
  in the repo; code + URL are what's shown).
- CASH-TENDER FRAME PAIRING: new setting `watch_frame` (the ONE
  settings store, default OFF; Owner-panel toggle "Camera frame
  on cash sales"). When it is on, the register keeps a hidden
  watch stream alive and, AFTER a cash tender completes and the
  receipt shows, grabs one frame and posts it to new
  server/frame.php, which saves data/frames/<txn>.jpg and flags
  the sale `frame: true`. The frame is a passenger, never a
  gate: it is grabbed only from an already-live stream (the
  camera is never started at tender time), every failure is
  swallowed, and card sales never capture. Transactions history
  marks framed sales ("· 📷 frame") and the detail's "📷 View
  counter frame" button opens the photo (frame.php?txn= serves
  the bytes; data/ stays outside the web root). frame.php
  refuses non-cash sales (422), unknown txns (404) and non-image
  uploads (422).
- CUSTOMER SENSING ON DEMAND: a counter word + a customer word
  in one chat ask ("is there a customer at the counter?" /
  "counter ma customer chha?") is answered by the client, never
  the brain: with a live watch stream it grabs a frame at once;
  with a camera but no stream it opens the capture view in sense
  mode; with no camera it replies plainly "No camera on this
  register — …". The frame goes to photo.php with mode=sense —
  one new vision call (golpos_meta_sense_counter: how many
  people face the counter?) and one composed reply; the lane
  stages nothing and never touches the catalog/purchase lanes,
  and a paired phone photo can answer the same ask through the
  inbox.
- VISIBLE INDICATOR: a "● Camera on" pill in the header shows
  whenever ANY stream is live (watch stream or capture view),
  and the capture view carries the same pill. A denied camera is
  remembered per page so the till never nags; an explicit camera
  tap or a settings save resets the memory.
- Verified on throwaway copies only (nothing deployed): PHP
  battery over real HTTP, 40/40 — watch_frame default/save/
  read-back; the frame lifecycle (cash Beat-1 6.67/0.53/7.20 →
  frame saves byte-identical against the txn, serves back
  byte-identical, ?history carries frame: true; an unframed cash
  sale has no flag; a card sale's frame POST is 422 and its GET
  404; unknown txn and non-photo refused); the pairing lifecycle
  (mint → code resolves → a paired bill upload lands its result
  in the REGISTER's inbox and not the phone-claimed session,
  read-and-clear; token REUSE inside the window stages the SAM
  lane's card into the same inbox; bogus and expired tokens
  refuse plainly, push nothing, and the expired one is pruned);
  sensing (the honest eyes-didn't-answer reply locally, no card,
  nothing staged, paired sense lands in the inbox); and the bill
  flow completing under the register's session — the post-vision
  seam (golpos_bill_process driven directly, the bill battery's
  method, since no Meta key exists on this box): bill_await in
  the register session → "sabai ma 40% margin" stages the full
  card with the new line at $3.36 → PIN create at "gol" → the
  product is created with its barcode, matched stock receives
  +24, the purchase records once. Regressions in the same run:
  card Beat-1 at 3% surcharges to 7.42, the price trap still
  stages $3.00, the day total answers. JS battery — the real
  app.js under a stub DOM against the real server, 27/27 — boot,
  Beat-1 cart totals and the cents-shift keypad unregressed; the
  voice seam with a fake SpeechRecognition on the vm context
  (register mic opens the drawer and starts an en-US recognizer,
  interim text fills the input, the final "add 3 dollars in
  coke" travels VERBATIM through sendChat on the register's own
  session and its brain response equals a direct typed POST of
  the same words, card id/stage time aside; after a Nepali send
  the next recognizer opens ne-NP; second press stops; no
  recognizer → plain toast; no-speech error → plain toast); the
  sensing interception (English + Nepali asks earn the
  no-camera reply with NO brain call; an ordinary ask still
  reaches the brain); the tender passenger (cash Beat-1 records
  through tender(); watch ON with no camera — the sale still
  completes and no frame is fabricated); the history frame
  marker + viewer wired to frame.php; a paired sense result
  rendering into the register chat with the drawer opened.
  php -l / node --check clean.
- Device-only proofs, flagged plainly for the owner's Oct 30
  device pass (they CANNOT run under the stub DOM or the local
  server): real SpeechRecognition accuracy and mic permission on
  the filming device, getUserMedia on the register + phone
  browsers, the watch-stream frame actually attaching at a live
  cash tender, and the phone page end-to-end on a real phone.
  Nothing deployed.

## Day 3 (Oct 28) — Brain truth pass: the chat may not bluff (4254d1a)
- Owner live-caught the till chat bluffing (11:00 ET): a poster ask
  refused with "I can't make offer posts", two "I've staged a card"
  claims with NO card in the response, and "okay stage it" answered by
  asking for the item and price it had just been given. Parent
  reproduced all four over real HTTP before the fix.
- Root causes (dispatch-level, not wording): the price-change gate
  demanded a price WORD beside the verb, so "change coca cola 20oz to
  3$" and the bare "coca cola 20oz to 3$" fell through to the free-form
  composer, which improvised the staging claims; "$" after the amount
  ("3$") was not money to the span layer; the poster lane knew the word
  "poster" but not "offer post".
- Fixes: number spans read "$" on either side of an amount (and counts
  blank both shapes); a bare price-change gate at the meaning layer
  (item resolves + price said as money or bound to "to", or a change
  verb with the price missing/tied — questions/want/receive verbs
  excluded); an anti-bluff guard in out() that replaces ANY staging or
  sale claim made with no card/done/pending behind it (past-tense claim
  patterns only; the templates' future-tense promises never trip it);
  and a session price wait (price_await, mirroring bill_await) so the
  owner's follow-up carrying the missing piece completes the staging,
  while "stage it" about a pending card answers with that card's real
  summary and returns the card.
- Owner rulings folded in the same pass: offer posters belong to
  MR.STORE's Gol — the POS poster dispatch is REMOVED, poster asks get
  the one-line route before any lane can read a deal's price as a price
  change, and the "Make an offer poster" chip leaves the chat UI; and
  the add-a-sale law — "add sales" / "sale add gara" / "bikri hal"
  route to the register truth (a sale exists only when rung and
  tendered; chat fills the CART by name), with sale claims ("sale
  added", "recorded the sale", "bikri hal") under the same guard.
- Verified on throwaway copies only, per the owner's simulation law
  (nothing deployed, no live state touched): php -S + python battery
  over real HTTP with fresh sessions, 138/138 — the four owner
  sentences verbatim (route reply + real price_change cards, sku 1001,
  2.19 -> 3.00, in both verb and bare forms; "okay stage it" returns the
  same card id with its summary); context completions (price follow-up,
  item pick); sale-add asks leave sales.json byte-unchanged; the guard
  proven at the composer seam with a bluffing stub (staging claim and
  sale claim both replaced, honest fallback untouched); trap battery
  21/21; gate2 repo battery 60 cases structural (7 reply_lang notes =
  the documented language-neutral price line, byte-identical on
  pristine HEAD); access 12 incl. the Beat-3 PIN execute -> 2.50 ->
  restore 2.19 choreography and unelevated "gol" refused; auto-batch
  manual-seals-nothing / auto-seals-once; Nepali gate 4/4; api money
  seams (Beat-1 7.20/2.80, 3% card surcharge 7.42). php -l (all server
  files) and node --check clean.

## Day 3 (Oct 28) — Icon pass: live SVG icons, no emoji glyphs on controls
- Owner order (11:03 ET): "gol ko sabai icons camera, mic haru send
  button haru malai image haina icon nai deu — live icons." Every emoji
  that served as a button/control glyph in web/ is now a real inline
  SVG (24px viewBox, stroke=currentColor so the three themes dress
  them, em-sized to the glyph scale each button had; no icon fonts or
  external files — offline at the till): header camera + mic
  (aria-labels added), Settings gear, all nine quick-ask chips, chat
  photo label + mic + paper-plane Send, voice pill and camera-on pills,
  Capture, scan-modal Copy/Send, and the app.js-built staged-card
  Confirm check and counter-frame controls. Ids, handlers and label
  text unchanged; listening/camera animations sit on the containers and
  still apply.
- Proof: emoji scan — web/index.html 0 lines; web/app.js keeps emoji
  only in chat message text, toasts, a status line, a content feed line
  and the receipt cut-line (never a button). node --check clean;
  throwaway HTTP smoke: index 200 with 21 inline SVGs, served app.js
  node-checks.

## Day 3 (Oct 28) — Hisab-test fixes: reads never arm PIN-create; cancel clears a card
- The intense hisab test (62 cases across MR.STORE Gol + the register
  brain) graded the register 7/11 and reproduced two defects end-to-end:
  D-11 — "this month's total sales?" on a PIN-less register opened the
  PIN-create flow and the armed wait ate the next five messages as PIN
  attempts; D-13 — "cancel" with a staged price card fell to the
  fallback ("I can't cancel that here…") and the card stayed armed (the
  Oct 30 watch item, confirmed broken).
- D-11 fix (routing level): the gate arms PIN-create only behind a
  genuinely pending gated action (executing a staged card on the
  confirm word — the Beat-3 choreography, untouched) or an explicit
  admin/pin setup ask; any other gated ask with no PIN set gets the
  truthful pin_not_set line ("owner-only… say 'admin' when you want to
  create one") and arms nothing. The create-stage wait now bails like
  the verify stage: a worded message disarms it and runs fresh; only a
  purely numeric message in the wrong shape is a PIN attempt and keeps
  the wait with the format line.
- D-13 fix: the dispatch's first check — a cancel word with a card
  pending clears it deterministically and confirms the clear in one
  line, before the confirm block and every lane; the cancel-word list
  gains the romanized variants of its own words (radda, nahune,
  hudaina, nakara, नगर, नहुने).
- Verified on throwaway copies only: battery 151/151 (138 prior + 13
  new pinflow cases with session/pending STATE assertions — pin_stage
  null after a read, pending.json empty after a cancel, the hisab R08
  sequence verbatim). Two harness setups that leaned on the old
  read-arms-create behaviour now elevate via the owner's real explicit
  "admin" path. php -l clean. Nothing deployed.

## Day 3 (Oct 28) — Register order prep + D-12 day aggregates
- ORDER PREP (owner directive, Oct 28): ALL vendor work happens at the
  register. The vendor pack lives per store in data/vendors.json (name,
  aliases, rep days, delivery day, cadence + biweekly anchor, last bill,
  typical lines) and is taught by chat — "lipton ko rep sombar aaucha"
  stages a vendor_teach card (the candidate name decides: a name equal
  to a pack vendor's name/alias re-times THAT vendor; any other real
  name creates a new one — "Valley Dairy" never snaps onto "Sunrise
  Dairy Fresh" via the shared word "dairy").
- On a rep day ("X ko order bana", or the morning greeting card, or the
  rep-day list), Gol drafts ONE order_prep card per vendor from three
  inputs in order: (1) What's Out + below-par shelf state, (2) sales
  velocity since the vendor's last delivery, (3) the vendor's typical
  lines (pack records + the register's executed purchases). Suggested
  qty = max(sold since last delivery, refill-to-par); an OUT line with
  no signal falls back to its usual bill qty — never an invented number.
  Lines carry last cost; the estimate sums costed lines only and says so.
- The staged card is edited in chat ("coke 12 hata", "pepsi 20 thap") —
  resolution is name/alias/distinctive-token only, never the resolver's
  token score (it once set the Coke line for a Sprite edit via "20oz").
  Confirm ("gol", PIN-gated like every business write) marks the order
  record (data/orders.json: prepared -> confirmed -> given) — the sheet
  the Owner panel's new "Today's rep visits" card prints/shares for the
  rep. "X lai order diye" records the handover. NOTHING is ever
  submitted to a vendor in the owner's name (Superior rule).
- D-12 (hisab): the register brain now answers day aggregates from its
  OWN sales log — best sellers by quantity and by revenue for today /
  yesterday, and yesterday-vs-today comparison (EN + Nepali), in a
  deterministic lane ahead of the deep-think lane; the gate treats them
  as the day total's family (cashier-domain reads). Week/month scopes
  stay with the deep lane. Best-seller matcher now hears "sold best"
  and "best seller", not only "best selling".
- D-11 / D-13 regressions re-run on this build and green (pinflow +
  cancel cases inside the battery below).
- Verified on a throwaway battery instance over real HTTPS: 41/41 —
  D-11 (3), D-12 (3), rep-day/morning (2), D-13 (4), order prep full
  circle stage->edit->PIN-create confirm->given->panel/sheet (16),
  teach create + re-time + nameless ask (8), sale-law/poster/price/day
  regressions (5). php -l clean; app.js node-checked. Deployed to
  gol-pos-dev + gol-pos-sim with FICTIONAL sim vendor packs seeded as
  data/vendors.json on both stages (Sunrise pack on dev, QA-flavoured
  pack on sim — no real Lucky/123 vendors, bills or figures anywhere).

## Day 3 (Oct 28) — PIN gate on register actions, mirror-only Owner panel, demo month, greeting/guard fix
- PIN GATE (owner live catch, Oct 28 12:12): the chat gate worked but
  the register UI's admin actions ran UNGATED — api.php close_day called
  golpos_close_day directly. Now Seal batch (new api.php seal_batch door
  to the ONE golpos_seal_batch over the unbatched segment), Close day
  (dropdown + Owner panel) and the Store open/close toggle run through
  golpos_sheet_pin (the Add Product gate: create in two entries when no
  PIN exists, verify otherwise, shared 3-strike 10-minute lockout). The
  client drives ONE shared PIN sheet (gatedApiPost): the request that
  unlocks is the request that completes the action; cancelling the
  sheet cancels the action. Once the session is in admin mode nothing
  re-asks. Ungated, as counter rows should be: Reprint last,
  Transactions, Scan link, Tax exempt, and the two instant flips —
  Printer (auto_open) and Camera frame (watch_frame) — a settings save
  touching only those keys passes; any other key is gated.
- OWNER PANEL = LIGHT MIRROR (owner: "tala arko mrstore aaeraxa" +
  85/15 rule): the Receipt Print Settings FORM is gone — no typed-in
  form exists anywhere in the panel. The card renders the same values
  as read-only lines with the note that Gol chat changes them. Panel
  keeps Today tiles + staged-card line, Close the day, rep visits,
  Demo feed, Receipt Loyalty, Reports. The Camera frame flip moved
  into the Settings dropdown beside Printer.
- CHAT PARITY for everything that left the panel — store name, address
  and phone already had setting_change lanes; two were missing and are
  added through the SAME card type: card_surcharge_pct ("card
  surcharge 3%", 0–10, out-of-range refused in words, never staged)
  and watch_frame ("camera frame on/off"). Both join the settings read
  reply; execution stays PIN-gated at "gol" like every setting card.
- WATCH AUTO-ON (owner, 12:24): setting ON means the camera is simply
  ON — the stream starts at register boot (loadSettings ->
  syncWatchCam) with the pill showing, no click. A boot attempt that
  meets an ungranted permission now fails SOFT (never latches
  camDenied) and arms a one-time retry on the first touch/keypress;
  toggling OFF also detaches the dead stream from the watch video
  (camStopAll). The header camera button stays the capture door.
- DEMO MONTH (Fix B scope-up: "pos ma simulated data hunu paro"):
  scripts/seed-demo-history.php seeds a standalone demo stage with 29
  past days ending yesterday — baskets from the tree's OWN catalog,
  the register's exact tax/total math, cash tendered/change, one
  sealed batch + one close per day, every sale marked demo-seed.
  Today is never seeded ($0/0 for filming) and catalog onHand is
  untouched. Dev body: 887 sales, $9,178.79 gross (Sep 29–Oct 27).
- GREETING / GUARD (owner voice catch, 12:25): "Hi how are you today"
  got the sale-route lecture. Two causes, both fixed. (1) The
  day-total lane matches any "today" — a new whole-message small-talk
  shape (golpos_is_smalltalk: greeting + how-are-you tail, EN + Nepali)
  routes to the friendly fallback BEFORE the day lanes, and the access
  gate exempts the same shape ("how is your day" was arming the PIN
  gate as a report ask). A greeting followed by a real ask is NOT
  small talk and falls through untouched. (2) golpos_claims_sale
  matched status phrasing — the day lane's own zero line "No sales
  recorded yet today" was replaced by the lecture it was meant to
  prevent. The matcher now takes accomplishment shapes only
  (first-person completion, verb-first done claims, bare
  done-assertions, has-been claims) and reads "sales recorded …" as a
  claim only with no status continuation (so far / yet / today / at
  <time> / in the last …). The fabricated-completion shapes from the
  truth pass still trip it (17/17 harness).
- Verified on throwaways over real HTTP + rendered Chrome (CDP):
  battery A (gate matrix + parity) 45/45, battery B (greeting/guard
  routing) 13/13, claims harness 17/17, seed validation 16/16,
  rendered click paths: Transactions month -> expand -> reprint 6/6,
  PIN sheet (challenge -> wrong refused -> correct completes ->
  elevated silent) + panel mirror (zero form controls) 9/9, watch
  auto-on/toggle 8/8. php -l clean; app.js node-checked.

## Refunds + store-credit redeem (Oct 28, 2026) — commit 6564556, stamps 08c3d5b

Refunds attach to a recorded sale, never a free-typed amount. One quote
function (golpos_refund_quote) prices both doors — the Transactions
sheet and the Gol chat refund card — off refunds.json as the one record
of truth (per-line remaining = sold - already refunded; beyond-qty and
double refunds are refused in plain words). Execution is PIN-gated per
the store's refund_pin setting, money returns to the ORIGINAL tenders
proportionally with the exact remainder on the completing refund, and
returned catalog goods restock through the one receive discipline
(standalone catalog increment; link mode rides mrstore_adjust_stock —
proven live on the QA sim: Ice Bag onHand 1114 -> 1115). Batch truth:
an unsealed sale's refund nets inside its segment; a sealed-batch
sale's refund rides the current segment as a negative entry; a sealed
batch record is never edited (battery proves byte-stability across a
refund AND the next seal). Settings ride the chat staged-card lane
(refunds_allowed on / refund_pin on / refund_window_days 30); the
panel mirrors them read-only. Chat finder takes the hints a person
gives (day, amount, tender, item); ambiguity lists candidates and the
pick stages from the session await — never a guess.

Store credit is a TENDER, never a discount. The till's Credit button
looks the customer up by phone (the loyalty master key) against the
Receipt Loyalty points ledger inside loyalty.json (1 point per $20 of
counted receipt spend, 1 point = $1) — the same balance the customer
page shows. Everything is validated before anything records; the spend
is keyed by sale id in the ledger, so a double-tap or retry cannot
double-spend; the remainder splits to cash/card with change on the
cash part; tax math never sees tenders (battery: identical tax with
and without credit). Refunding a credit-paid sale restores the credit
portion, keyed by refund id.

Link mode, named plainly: MR.STORE Festival points are derived display
values with no spendable ledger the register may write, so a linked
register hides the Credit button, refuses a credit tender with an
honest 422, and answers credit asks in chat the same way — the
link-mode spend is a NAMED FOLLOW-UP, as is an MR.STORE journal refund
ticket (store-api/card-report.php has no refund ticket type; the
register's own sales/refunds/batches records are complete in both
modes).

Battery: 78/78 over real HTTP on a throwaway instance — refund groups
(full cash, full card, line partial, beyond-qty refusal, double-refund
refusal, sealed-batch negative + byte-stability, window refusal then
chat widening, refunds-off refusal, PIN required/wrong/right/off
paths, settings staged via chat, ambiguous chat refund pick,
single-match + cancel), credit groups (fixture lookup, unknown phone,
full tender, split remainder + change, tax identity, over-balance,
double-spend blocked, refund-restores-credit, credit_redeem off/on via
chat), and the employee-lane smoke regression (lock -> employee PIN
unlock -> stamped sale -> close demands admin PIN -> close lands ->
locked again). Live proofs on both stages with throwaway data, then
both stages restored byte-for-byte from the pre-build snapshot (dev
887 / $9,178.79 / 29+29, owner people/sessions/settings md5-identical,
plus the fictional credit fixture Ava $12.00 / Ben $25.50 as the
spec-ordered end state; sim 3,000 / $57,301.04 / 30 closes, virgin
roster, QA stock netted out).

## Register Camera Part B (Oct 28, 2026) — commit ce265fa, stamps ce265fa

MR.STORE is the master of the register camera; Part A put the keys on
the store's shop_profile (register_camera master, register_camera_audio,
register_camera_record — strict booleans, children effective only under
the master). Part B makes the POS obey them. One function —
golpos_regcam_state() in server/mrstore.php — is the single source of
truth, reading the SAME profile record mrstore_settings() already
reads (top-level mirror keys first, store_ops fallback; absent = OFF):
effective state = mode, master, audio/record (master && child), the
local flip, and stream = master && flip. api.php?settings (and the
settings POST response) carry the block, so the client never guesses.

Link mode (sim): master OFF -> the register NEVER starts a stream —
client gates keyed on regcam.stream (syncWatchCam, pair flows, capture
view, sense) plus a server-side second gate: frame.php refuses a
still-frame POST in link mode while the master is OFF. The flip can be
saved under a held master but turns nothing on; a held sense attempt
answers in chat, honestly. Master ON + flip ON -> auto-start as
before; master ON + flip OFF -> down at that till. The audio child
joins the stream request (getUserMedia audio flag; a live stream is
restarted when the flag changes under it) and rides the captured
evidence metadata. The record child turns a CASH tender's evidence
into a ~4s MediaRecorder event clip (still frame kept beside it) via
frame.php's new clip door — cash only, record-effective only, video
mime only, one clip per sale, re-take replaces — served back at
frame.php?clip=<txn>; Transactions rows show the clip (with a "with
sound" marker) beside the still. Card sales never capture (unchanged
law, clip and frame alike). Gol chat answers camera state from the
same facts in the asker's language (en / Romanized NP / Hindi /
Devanagari); in link mode the master's children are chat-read-only —
the answer names MR.STORE Settings and stages nothing; a bare "camera
on/off" intent is held the same way while the master is off. The
footage shelf ships register-side: api.php?footage (records) and
server/footage.php (owner-gated list page, same session gate as the
?team feed). The MR.STORE desk leg inside gol-chat.php is a NAMED
FOLLOW-UP — that file was off-limits this build (another build owns
it today); store-api/gol-chat.php untouched.

Standalone (dev): no master above the till — the local flip stands in
for it, and audio/record exist as LOCAL settings of the same names
(defaults OFF = the shipped still-frame behavior), staged via chat
cards like any other setting; the Owner panel + Register Settings
mirror the effective state read-only in both modes.

Battery: 51/51 over real HTTP on two throwaways (link instance bound
to the QA store + a profile toggle drill, standalone instance) —
master-off block (settings state, NP + EN honest state asks, held
flip intent, server frame refusal, no file saved), master-on matrix
(flip off = down, flip on = up, audio flag in the settings block the
client receives + served-JS code path asserts), record on/off (cash
clip lands with txn id + audio metadata, footage endpoint + page,
still-only when off), card-sale refusals on both doors, unlock-photo
door regression (stamped photo on the team feed), and the smoke slice
(unlock -> sale -> refund quote -> chat day close). Restorations:
QA shop_profile back to ABSENT (the pre-drill state), product_catalog
+ stock_overrides byte-exact incl. updated_at, today's QA journal
file md5-restored, throwaways removed. Live proofs: sim regcam reads
link/all-off, sim month intact 3,000 / $57,301.04; dev standalone
defaults, month 887 / $9,178.79, today 0/0, 29 closes, watch_frame
OFF, owner people/sessions/settings md5-identical — his PIN never
touched. NOT claimed: real microphone audio inside a real clip — no
device exists on this machine; the Oct 30 hardware pass owns it.

## Customer Identity Lane — one customer, one record, every surface (Oct 28)

Owner directives 14:03–14:15 ET: a customer known on the website is
known at the register (phone = master key); redeem is NAME-FIRST at
the till with a human pick on collision; and the register carries a
visible REDEEM BUTTON — tap, number, credit into the current sale.

Truth map (before): storefront chat-gate contacts landed in
customer_chat_<store>.jsonl (a chat log) and chat_leads.jsonl; Festival
points lived in festival_customers.json as a DERIVED display balance
(points_balance = lifetime-spend math + points_adjust; the counter
redemption was an owner hand-edit of points_adjust — no spendable
ledger existed, which is why the refunds lane honestly refused link
mode); the register kept its own standalone loyalty.json ledger.

Shipped: store-api/customer-ledger.php + customer-identity.php — one
record per store in data/customers_<storeId>.json, keyed by normalized
phone (Festival's own fest_phone rules), with an append-only POINTS
LEDGER: earn keyed by receipt number (store-wide first-claimer lock),
redeem keyed by sale id, refund-restore keyed by refund id; balance is
always the ledger sum. Festival earn math mirrored (cumulative
floor(spend/20) deltas, 72h window, expired receipts lock their number
at 0). The HTTP endpoint's writes are fenced to the QA store; the POS
bridge (mrstore_customer_*) calls the same lib in-process — one write
path per surface family, one record underneath. gol-pos facade
(golpos_credit_account/balance/add) routes by mode, so the till
tender, the chat lane and the refunds executor all spend/restore the
same ledger on both sides of the link; the two link-mode refusals
(GET ?credit, sale credit) are gone.

Three redeem doors, one write path (the api.php sale tender):
1) the visible Credit BUTTON + number entry (its GET door was the
   refusal — now live against the real ledger);
2) NAME-FIRST in the Credit sheet: matches with phone tails, the
   human taps the pick, the pick re-enters as a phone lookup;
3) the employee chat door: "Ram le redeem garna khojchha" / "Ram
   wants to redeem" stages a credit_attach card (cashier domain —
   deliberately absent from the admin gate list); confirm hands the
   till an action that sets creditApplied — chat never writes the
   ledger itself. Chat balance reads accept a name or a number;
   collisions list matches and ask.

Battery: 45/45 over real HTTP (endpoint/QA 12, sim link 28, dev
standalone 5) — gate register/merge, receipt earn + both duplicate
locks + expired-window lock, cross-surface spend (website-earned
balance redeemed at the till), button flow (check → apply → tenders
split credit $5 + cash $4.71 on a $9.71 sale, balance 8 → 3),
once-per-sale retry refused, over-balance 422 clean, refund restores
via refund-id entry (+ duplicate restore refused), chat-door stage →
confirm → tender spend, NP + EN doors, zero-balance honesty, employee
+ refunds smoke. Two battery-caught defects fixed in ada842e: the
chat-door shape names no credit word, so the lane gate now keys on
the attach shape itself; the name read stripped filler but kept the
trailing "?", which matched nothing.

Restorations byte-verified: dev 887 / $9,178.79, today 0/0, owner
admin PIN untouched (dev refund EXECUTE was never attempted — it is
his PIN's gate; quote proven instead); sim 3,000 / $57,301.04, no
people.json (the battery's throwaway PIN died with the restore); QA
customers file + lock removed (the machinery stays, battery data
gone), QA journal restored to its 99-line pre-battery snapshot, QA
stock Coke 2130 / Pepsi 1795 identical (every battery sale refunded);
Lucky festival files md5-identical — read-only throughout, and in
fact still empty ([]).

NOT done / unproven: Lucky go-live — festival-api.php's writer is a
different file/format, so switching Lucky over stays a one-line
owner-word follow-up: point festival-api's register/earn writes at
cust_register/cust_earn_receipt for the Lucky store id and lift the
endpoint + bridge write fences for that id (the record file format is
already the one this lane ships; live Festival data is empty, so
there is nothing to migrate). The button flow was batteried over its
exact HTTP contract plus served-asset checks (stamp ?v=4d84a9a,
node-checked on both stages) — a physical finger tap-through is the
Oct 30 hardware pass, same as the camera/mic proofs.

## Visual Reports — graph cards in the register chat (Oct 28, 2026)

Owner directive (Oct 28, 14:29 ET): reports in the POS chat must SHOW
as graphs — "physical representations so that judges or users can
enjoy the view" — in the chat itself, never a separate reports screen
(no-menu law).

PROVENANCE LAW (the heart of the build): every report answer now
carries ONE structured payload (graph: kind, title, unit, series of
{label, value, sub?, highlight?}, total, focus?) computed by the SAME
lane, from the SAME golpos_day_summary / golpos_day_items reads that
compose the sentence above it. The client renders the picture from
that payload only — there is no second data read for the graph, ever.
If the text says $1,240, the bars sum to $1,240 by construction.

To make that true for the week/month lane, golpos_week_facts now
derives every total (week, month, tender split) from
golpos_day_summary instead of raw sale sums: before, a refund rung
today made the week line and the day line disagree (the week counted
the pre-refund gross). Item revenue (top sellers) still comes from
the raw log — line detail lives only there.

Shapes: day asks and week asks -> 7 vertical bars (trailing 7 days,
today lit #2563eb, the rest #93c5fd; the day lane also pins a focus =
today's figure, the exact number its sentence states); day compare ->
two paired bars (yesterday vs today, the sentence's two figures);
best sellers -> horizontal bars from the SAME revenue sort the text
names (text's top-3-by-revenue are bars 1-3, value for value; sub =
×qty); month asks -> a day-by-day spark (area + line, first/peak/last
labelled). An all-zero range is flagged empty and renders an honest
flat card ("Nothing to draw yet") — never an invented shape.
Non-report answers carry no payload and render exactly as before.

Client: graphSvg() is a pure payload -> inline-SVG function (Gol blue
family, no deps, no chart library) and chatGraph() drops the white
card into the conversation under the reply in sendChat. Any new icon
would be inline SVG per the standing rule; this build adds none.

Battery: 116 checks over real HTTP chat turns on two throwaways
(fixture week: $100 / $150.50 / $0 / $200.25 / $175 / $225.75 / $70
after a $10 refund rung today): 86 payload asserts (each shape in EN
and NP — payload present, series sum == payload total == text figure
where the text states one, labels == asked range, today highlighted;
empty instance -> flat cards on day/week/best/compare; price,
greeting, stock and a staged price-change carry NO graph), 19
renderer asserts on the real captured payloads (values, names,
compare labels untruncated), 11 employee-lane smoke (unlock as a
throwaway cashier -> ring a sale -> day ask's graph focus moves to
the new $74.73 -> admin-gated day close with PIN -> locked -> PIN
please), plus live sim proofs: the graph's 7 bars equal an
independent hand-sum of the sim journal day by day (Oct 22-28:
$1,898.26 / $1,870.66 / $1,864.94 / $1,588.49 / $1,873.62 / $1,954.32 /
$1,741.72; week $12,792.01) and its best-seller bars equal the
journal's item aggregation (10 live sim proofs; the week lane's own
ask on sim is refused by the owner-only gate — sim's roster is
virgin by design — while its 7 bars reach the sim journal through
the day lane's graph, proven above).

Two pre-existing catches named, NOT fixed here (routing/language
layer, not the graph): NP "...bikri bhayo?" phrasings are captured
by an earlier lane as an item statement (the owner's own "aaja kati
bho?" routes to the day lane and graphs correctly), and short NP
forms like "yo hapta kasto cha?" don't trip the language mirror's
stem list, so those answers (and their graph titles) stay English —
the payload itself is correct. Both belong to a language pass with
its own regression battery.

Restorations: throwaway copies deleted from the server after the
battery; dev and sim data untouched by the proofs (sim sessions file
restored byte-exact; journal still 3,000 / $57,301.04; dev still
887 / $9,178.79, today 0/0, owner admin PIN untouched).

NOT done / unproven: film-distance readability of the value labels
is the Oct 30 device pass. The MR.STORE store-Gol chat has a
different renderer and does NOT draw these payloads yet — a named
follow-up; the payload contract is ready for it.

## The Pay Ask lane (Oct 28, 2026) — commit fa8d795, stamps d6a6e13

Owner directives (Oct 28, 14:30–14:34 ET): the employee asks in HER
OWN Gol session "can I get my pay?" → Gol computes her hours from the
real record × her roster rate → shows HER the math → her confirm sends
a staged request UP to the owner (hours + rate + amount + period) →
owner approves/declines on the card → approval books the pay ONCE.
Plus the owner-chat rate change ("Pranisha ko pay 13 bana") shipping
in the same lane.

THE MAP (recon before build, as ordered):
- Hours evidence: the register's own unlock journal
  (data/unlocks.json). Locks were NOT journaled before this build —
  golpos_lock_session now writes a kind:'lock' event (the ?team feed
  filters them out; they are evidence, not feed rows). Stint = her
  unlock → earliest of (her lock · any later unlock · that day's
  close by business date), SAME-DAY enders only: a next-day unlock
  proves nothing about when she stopped, so the stint is UNENDED —
  named in her reply, priced zero, never stretched across days (the
  battery caught the stretch: 15h + 17.45h phantom days; fixed before
  ship). A stint still open today with her session live counts to
  now and says so.
- Rate home: NO rate existed anywhere in register code. MR.STORE's
  Settings roster ($11/hr at Lucky) is not bridge-readable today (no
  bridge lib exposes a roster). PINNED TRUTH v1, BOTH modes: the
  register's local people record — new `rate` + `rate_history[]`
  ({rate, from}); a bare rate seeds from the record's `created`.
  Bridge read of the Settings roster is the named follow-up.
- Booking path: NO bridge-reachable pay write exists (mrstore.php
  libs: catalog/stock/customers/calnotes/team-chat/bills only;
  store-api/staff-pay-once.php exists but is an unwired endpoint).
  Approval therefore books REGISTER-side: the request record in
  data/pay_asks.json is the pay-ledger entry, its id the booking
  reference. The Books salary write is the named follow-up — no write
  path was invented.

SHIPPED:
- Her ask (cashier domain, NOT admin-gated; credit_attach precedent):
  EN/NP/HI/Dev shapes ("can I get my pay", "mero talab?", "talab
  paincha?", ...). Open period = day after the last paid period,
  refined by covered_until interval accounting: a paid request
  covers time up to the latest stint end it priced, so hours she
  works later that same day price in the NEXT period — never lost,
  never double-counted. Day-by-day lines shown to her; unrated days
  stage NOTHING and are named (v1 honesty); a correction ("ma
  mangalbar 4 ghanta thiye") appends VERBATIM to her staged card and
  rides to the owner's card — evidenced hours never change.
- Her confirm ("gol") mints ONE pending request per employee
  (enforced at staging and at execution); a second ask returns the
  pending one's status. Request record: employee, period, per-day
  lines, per-rate segments, hours, amount, corrections[], unended[],
  covered_until, status, created/decided_at/decided_by/booking_ref/
  seen.
- Owner side (no new screen): pending requests ride the greeting
  team line and the ?team payload (Owner-panel Team mirror,
  read-only); "pay requests" stages the approve card (headline +
  period + her correction lines quoted). Approve → paid once (the
  status check is the lock; a decided request can never re-pay).
  "decline" (whole-message intent, intercepted before generic
  cancel) closes it declined; she sees the plain note once in her
  session. Plain "cancel" clears only the card — the request stays
  pending.
- Rate change (admin domain): name + pay word + number; staged card
  "Pranisha — $11 → $13, from today" (old null renders "no rate on
  record"); "by $2" phrasing computes old+2 and shows it; cancel
  writes nothing; confirm appends rate_history effective today —
  history never rewrites, and a period SPANNING the change splits at
  the change date on her read and the owner's card alike.
- Language mirror: pay stems (talab/paincha/jyala/ghanta/mero) added
  to golpos_lang's NP list — "mero talab paincha?" previously scored
  zero and Gol answered her in English.
- Client: chatCard renders pay_send / pay_approve / rate_change;
  Owner Team block lists waiting pay requests from ?team.

BATTERY (throwaway instance, fixture employees with planted
journal; 57/57): EN + NP asks → math equals hand-computed session
hours; SPANNING SPLIT hand-check: Oct 20–22 24.5h @ $11 (8.5 + 8 +
8-via-close) + Oct 24–25 11.5h @ $13 (6 + 5.5) = $419.00, both segment
lines shown; correction verbatim on her card AND the owner's card,
amount unchanged; duplicate ask → pending status, still one record;
approve → paid with booking_ref = record id, second approve
impossible (nothing waiting); decline → her next ask opens with the
declined note once, then a fresh card stages; card-cancel leaves the
request pending; same-day post-payment stint prices $13.00 in the
next period and never double-counts; open stint ≈0.5h counts to now,
post-pay re-ask finds nothing; Rita (no rate) → honest no-rate
reply, no card; Laxmi's replies never contain Pranisha's figures;
employee "pay requests" and employee rate-change both stop at the
PIN gate and write nothing; rate-change cancel leaves roster +
history untouched; smoke regression unlock → sale → day ask → tasks
→ lock (journaled) → close all pass.

Restorations: the throwaway (gol-pos-paytest) is deleted after the
battery; dev + sim data directories are md5-identical before/after
deploy (code-only ship): dev 887 / $9,178.79, today 0/0, owner's
admin PIN and people record untouched; sim 3,000 / $57,301.04,
people/sessions pristine. Lucky/123 production rosters were
READ-ONLY recon throughout (the $11 figure was never a write
target).

NOT done / follow-ups (named, not hidden): (i) Books salary write on
approval — wire store-api/staff-pay-once.php or a bridge pay lib;
(ii) bridge read of the MR.STORE Settings roster rate (register-local
record is v1 truth in both modes until then); (iii) link-mode hours
still come from the register's own journal, like tasks; (iv) an
unrated day inside an otherwise rated period stages nothing in v1 —
the reply names the days; partial-send is a v2 decision for the
owner.

## Day 3 (cont.) — Price-Gap Engine (owner design, Oct 28 15:20 ET)

"Try to get the price, cost price — the items that are empty. Make an
engine that will slowly enter one or two prices of an item. Pranisha
is just sitting in front of the POS, the Gol chat right in front of
her, and it reminds her: let's enter the price of Coca-Cola, because
it wasn't there and nobody was caring about it." Spec: "THE PRICE-GAP
ENGINE" in register-employee-tasks-spec.md. Commits b105025 (feature)
+ a658a7b (stamps ?v=b105025). Coordination note: the lane was built
alongside the Pay Ask build in the same tree; the api.php ?gap
endpoint and the brain gate-list / card-summary / executor hooks rode
into the Pay Ask feature commit (fa8d795) as working-tree state, the
rest landed in b105025 on top of a141541 — final tree verified whole.

What landed:
- server/pricegap.php — the engine. SENSE: golpos_gap_scan walks the
  catalog (price gap = no real shelf price: unset/<=0 standalone,
  price_source sim-demo in link mode; cost gap = no numeric cost) and
  keeps data/price_gaps.json reconciled with the records (fills
  stamped when a scan sees the value land; a lost value reopens as a
  fresh instance; a deleted product closes 'gone', never a fill).
  Generator kind price_gap (subject gap:<sku>, one task per product,
  one item per missing field, reason stated) spawned from
  golpos_tasks_sync section (4), routed to the register-duty holder
  (golpos_roster_with_duty 'register kholne/banda garne'), unheld ->
  owner inbox with the honest suffix, owner-yield + dedupe + done-
  guard inherited from the shared $spawn closure. Closure by evidence:
  golpos_gap_reconcile_task in the reconcile chain closes an item when
  its field is on the record, the task when all are.
- GIVE: api.php ?gap&session&cart&idle — her identity from the
  session (the ?mytasks resolution; locked/admin -> silence). Server
  enforces the calm: cart must be empty, idle >= 60s, <= 2 prompts
  per person per day, >= 20 min apart, never over a pending card,
  never re-arming an unanswered wait, never an item under a 7-day
  don't-know backoff, never an item she snoozed this shift (a snooze
  dies at her next unlock). Arming session gap_await and logging the
  prompt are one act. app.js polls every 15s (lastBusyAt stamped in
  renderCart) and renders the one bubble; her unlock brief carries
  the one-line promise (golpos_gap_brief_line) and her task ordering
  ranks price_gap last.
- STAGE+CONFIRM: the brain's gap-wait handler (beside bill/price
  waits, before the gate) — a number stages price_fill / cost_fill;
  an ambiguous number re-asks with its candidates; "thaha chhaina"
  records the 7-day backoff and answers kindly per field; a dismiss
  snoozes; cancel snoozes; anything else drops the wait untouched.
  Both card types are in golpos_needs_admin's confirm list, so
  execution demands the admin PIN exactly as a price change does
  (Beat-3 choreography). price_fill executes through the price lane's
  write (edit + golpos_save_catalog; the link sync carries it to
  MR.STORE). cost_fill executes through golpos_gap_write_cost:
  standalone sets the local catalog's cost key (added when absent);
  link mode calls the new mrstore_set_product_cost (load -> edit ->
  save on the MR.STORE record, QA fence, link-logged) and a refusal
  is reported as a refusal with the gap left open. Card summaries and
  app.js renderers exist for both types. NOTHING invents a figure:
  scan, prompt, cards and the owner line read records only.
- Bills stay the cost truth: golpos_execute_bill's matched-line
  refresh now also FILLS an absent cost (standalone; the link-mode
  comment stands). The link projection (mrstore_catalog_with_stock)
  now carries MR.STORE's cost when the record has one.
- Owner visibility: golpos_team_line gains "Price gaps: N filled
  today · M waiting (price/cost record missing)." from the state
  file; silent when there is nothing to report.

Battery (fresh-seeded throwaways gol-pos-gap + gol-pos-gap2, real
HTTP, deleted after): 35/35 — generator creates tasks for the
price-gap and cost-gap items only, once each across two unlocks,
complete item silent, both-fields product = one task with two items;
idle gates (cart=2 blocks, idle=10 blocks, idle=120 prompts price
first); her "2.19" stages price_fill; her "gol" stops at the PIN
gate with the card still pending; the owner PIN executes it; catalog
reads $2.19; the task closes by evidence with no regeneration; the
bill probe fills Geek Bar's absent cost ($7.25) and refreshes
Complete Chips ($0.80 -> $0.90) through golpos_execute_bill with no
ask, and its task closes by evidence at the next sync; the owner
greeting line reads "2 filled today · 2 waiting"; smoke — a cash
sale rings, the owner's price_change lane stages + executes
untouched, her price ask answers the new $1.75. Second instance:
price-before-cost ordering, the 20-minute spacing blocks an immediate
second prompt, "pachi" snoozes (recorded; the next prompt skips to
the cost item), "thaha chhaina" records dontknow_until +7d with the
gap still open, and with both items parked the poll returns nothing.
Regression smoke on the same instance: 8/8 — employee unlock +
my-tasks, team_note stage/confirm/landed on the Team read, What's
Out stage/confirm/on the board, refund ask stages the card and the
elevated confirm executes it. (Two script-side lessons, not code:
"Complete Chips out" trips the done-mark matcher on the word
"complete" — a pre-existing quirk of that seeded name; a bare
"refund" with exactly one refundable sale stages the card directly,
no pick step.)

Live proofs: served app.js on dev + sim carries fetchGap + the fill
renderers and passes node --check; stamps serve ?v=b105025.
DEV (standalone, CLI probe): link off; the Sunrise catalog carries
no cost anywhere, so the scan finds 62 cost gaps and one sync spawns
62 gap tasks; owner line renders "0 filled today · 62 waiting";
sales untouched. SIM (link, CLI probe): link on, 28 products;
Coca-Cola 12pk projects price $8.99 with price_source sim-demo (the
QA record genuinely carries no retail — the register's shelf price
IS the fallback) and cost $1.875 (bill-learned, now projected); scan
finds 47 gap fields across the 28 products and one sync spawns 28
tasks; golpos_gap_write_cost on 'Dutch A' (cost NULL) writes $6.66
through the bridge, the projection reads it back, and the probe
restores the record to exactly NULL (key + updatedAt restored).
Restorations: both stages' data/ directories were snapshotted before
the proofs and restored wholesale after — dev verified 887 sales /
$9,178.79 / today 0, owner's admin PIN record present, price_gaps.json
absent again on both stages (its pre-proof state); sim data file set
back to the pre-proof inventory, people.json still absent (virgin
roster). (The md5 diff itself could not run — the remote shell lacks
process substitution; verification stands on the wholesale restore +
the state reads above.)

Follow-ups (named): (i) gap volume follows catalog truth — the QA
store's bill-learned catalog is mostly unpriced in MR.STORE, so sim
shows many price gaps, and the Sunrise demo catalog carries no costs
at all (62 waiting on dev): seeding plausible costs into the demo
catalog is an owner decision, not an engine gap; (ii) prompts are
v1-capped at 2/day — the cap lives in golpos_gap_prompt_for if the
owner wants a different patience; (iii) the mid-shift surface is the
15s poll — no push channel exists at the till, by design.

---

## Oct 28 (evening) — THE VERIFY LOOP tap (gol-pos side)

The register now journals every checkable figure it speaks. `out()`
in brain.php and `pout()` in photo.php append claims to
`data/verify_journal.jsonl`: money_day_total / money_range_total
(graph bars/spark points now carry their ymd), best_seller_rev,
close_figure, pay_math (staged pay cards: lines, segments, hours,
covered_until, unlock prefix), price_shelf, stock_count (link and
local modes), bill_arithmetic (photo.php bill cards; line identity
journaled as an explicitly uncheckable facet — a same-sum item swap
passes any arithmetic and is never asserted). Money claims carry the
append-only sales/refunds prefix counts, so the verifier re-derives
against exactly the journal the lane saw. The verifier itself lives
server-side (store-api/gol-verify.php, cron every 10 min): verdicts
ok / WRONG / uncheckable / superseded; on WRONG it tickets, births a
permanent battery case, and corrects in-thread on QA/sim/throwaway
targets only (gol-pos-sim active, gol-pos-dev observe; live stores
observe-only until the owner hears the battery result).

WRONG-PLANT battery (throwaway copy gol-pos-vtest, deleted after):
16/16 — wrong day total, wrong price, wrong best-seller, bill sum
off, close-record poisoned after the fact (kind: source), catalog
twin row (kind: source), pay understated and overstated — all caught
with the recomputed truth; the hand-computed correct pay claim and
the matching close claim verified ok; the same-sum swapped bill
landed arithmetic-ok with identity recorded uncheckable, never
passed silently and never convicted. One battery lesson worth
keeping: the first "correct pay" plant was itself wrong — it forgot
the day's close ends the last stint (4.5h + 2h + 9h = 15.5h, $232.50);
the verifier's recompute was right and the plant was fixed, not the
verifier. Deployed to sim + dev (backups under ~/backups/verify-tap-*);
sim month 3,000 / $57,301.04 and dev month 887 / $9,178.79 verified
intact after deploy.

## Conversation continuity — context binding + armed clarifiers (Oct 28)

Owner catch (gol-pos-dev, verbatim): "How much is a Coke?" -> "$2.19"
-> "change is to $3.19" -> Gol asked "Which item...?" -> "coca cola"
-> Gol answered with the price and a cart offer. Two failures: the
price-change sentence carried its price but no item and no price word,
so NO deterministic lane claimed it (the bare-form gate needs a
resolved item before its money test can run); the free-form composer
improvised the clarifying question in prose and held no state, so the
one-word answer fell into the default lanes. There was also no
conversational context anywhere: the price-query lane resolved
Coca-Cola and recorded nothing.

Fix (reasoning level, no phrase patches):
- Session ctx (store.php session record): a lane that resolves a
  product records it as the conversation topic {sku, name, intent}.
  Set by the price-query lane, the price-change lane, and the await
  consumer on staging; read back against the CURRENT shelf
  (golpos_ctx_product), cleared on register lock. The free-form
  composer's context pack now names the topic too.
- The price-change lane binds the topic: an amount-carrying
  price-change imperative with no item in the words
  (golpos_amount_price_change: change verb + extractable price, not a
  question, no add/receive verbs) stages against the topic directly;
  with no topic, the price is remembered and the ask ARMS the wait.
- price_await gains a third shape {price, need_item}: the next
  non-question message is consumed as the item answer before any
  default lane; a tie answer narrows the wait to the {price,
  item_skus} shape; an unresolvable answer earns ONE plain re-ask
  (reasks flag), then the wait drops. All shapes now pass QUESTIONS
  through with the wait intact, and a bare greeting or smalltalk
  clears the wait and takes its normal path. Staging through the
  consumer records the topic.
- New template price_ask_item (en/np/hi/dev).

Battery (throwaway gol-pos-vtest, real HTTP): continuity suite 43/43
— the owner's transcript stages Coca-Cola 20oz $2.19 -> $3.19 AT
TURN 2 and executes on "gol"; pronoun and "tesko" forms bind;
no-context ask + one-word answer stages; question interruption leaves
the wait armed; greeting cancels it; "chips" narrows to the tie and
"doritos" binds only Doritos; unresolvable answers re-ask once then
drop; the Beat-3 PIN choreography (virgin register: stage -> "gol" ->
PIN create x2 -> replay executes, price verified $3.19, restored).
Gate-2 regression (60 cases, per-case state restore): 210 checks
pass, 1 fail — close-en-2 ("end of day close out") misroutes to a
report lane IDENTICALLY on unpatched HEAD; pre-existing, not this
change. Also observed pre-existing: "set 2 coke" stages a $2.00 price
card (count read as price by the shared extractor) — untouched.
