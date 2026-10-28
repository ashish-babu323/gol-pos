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
