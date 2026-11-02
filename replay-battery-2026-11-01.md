# Gol POS demo — QA Replay Battery (Nov 1, 2026)

**Surface:** LIVE server copy, luckymart.biz/gol-pos-dev/ (brain.php Nov 1 20:32 build — the Task 194 build), Sunrise Mart demo data.
**Run:** 22:42–22:47 ET (03:42–03:47 UTC), throwaway session `sess_replayqa_20261101` (admin stanza modelled on the owner's session; created for the battery, removed after; sessions.json restored byte-identical).
**Method:** POST {message, session} → /gol-pos-dev/server/brain.php, same payload shape as web/app.js `brainPayload()`. Session state (pending / ctx / price_await) and catalog.live.json were read back after every staging-sensitive turn.
**Snapshots:** data/*.bak-replay-20261101-204201 taken before the first message (sessions, catalog, purchases, expenses, price_gaps, verify_journal, settings, tasks).

**Score: 4 PASS · 1 PARTIAL · 3 FAIL · 1 FAIL (known-open)**

---

## Case 1 — "Why was today slow?" → **FAIL**

Reply, verbatim: *"This week: $13,328.43 across 1056 sales (today $0.00)."*

The bare `week_line` template again — no analytic content at all: no partial-day note (Nov 1 data ends 11:04 AM), no Sunday baseline (~$1,136 vs ~$1,889 all-days), no Halloween pull-forward, no ticket-size read. The deep-think compose still fails silently into the lookup fallback, exactly as traced in the proposal (§2). The figures also moved vs the owner's daytime test ($15,575.76 / 1224, today $274.78) because the demo clock (UTC) had rolled to Nov 2: "today" is an empty seed day and the 7-day window slid. A template that can't even hold its figures steady across midnight is the only thing answering the demo's most human question.

## Case 2 — "print the last receipt" → **FAIL**

Reply, verbatim: *"I can add items, quote or change prices (staged as a card), give today's total, and run the receipt print settings. Try: \"print settings\" or \"How much is Coke?\""*

The capability-menu deflection, word for word. No `show_receipt` action, no receipt payload — the chat door to the register's existing reprint stack is still unbuilt (proposal §3).

## Case 3 — "print settings" → **FAIL**

Reply: the full settings book under a "Receipt print settings:" header — 22 bullet lines: the 9 receipt fields **plus** store hours (8 AM / 9 PM), batch close mode, card surcharge, camera frame/audio/footage, refunds + refund PIN + window, credit redeem, and the MR.STORE login pointer. Unscoped plain-text wall, unchanged (proposal §4). No settings card payload in the response.

## Case 4 — "what was the expenses on October" → **PASS**

Reply: *"October 2026 — expenses. Expenses: $5,728.15 across 10 lines — biggest: Rent — October $1,850.00; Payroll — second half October $1,285.00; Payroll — first half October $1,240.00. Sales: $78,061.40 across 6056 sales (cash $43,351.16, card $34,710.24)."*

Expenses total exact, top-3 lines exact, **no purchase figure anywhere in the expenses total**. Known minor (unchanged from the proposal): the sales line rides along in the text on an expenses-only ask. No P&L 'overview' dcard rode this response (keys: actor, graph, locked, meta, pin_ask, reply).

## Case 5 — "October" (bare month) → **PASS**

Reply: *"October 2026 — the full month. Sales: $78,061.40 across 6056 sales (cash $43,351.16, card $34,710.24). Purchases: $3,657.12 across 12 vendor bills — Sunrise Beverage Co $2,042.52; Sunrise Snack Distributors $850.20; Sunrise Dairy Fresh $764.40. Expenses: $5,728.15 across 10 lines — biggest: …"*

Full month report from the bare month word; sales figure exact; purchases and expenses on their own separate lines (profit-rule separation intact in text).

## Case 6 — "Maverick Menthol 100 purchase $2,000" (text) → **PARTIAL**

Run twice: (a) cold, on a fresh session; (b) with a Newport item loaded into context first (a price quote — reproducing the owner's voice-test setup). **Both times: nothing staged.** Session state after each attempt: pending = null, price_await = null; no price_change on Newport or any item, no $2.00 anywhere. The reply each time was the capability-menu fallback (same text as Case 2).

The dangerous failure is dead — the utterance can no longer be stolen by the context-binding price lane. But the ideal is not met: no purchase card for Maverick at $2,000 stages, and Gol does not honestly say the purchase lane is missing — it deflects to the menu. Safety criterion PASS, capability criterion FAIL → PARTIAL.

*Side evidence:* the live catalog contains a fossil product literally named **"purchase 1000 newport 100s"** (sku 1063, $1,000.00) — residue of the owner's earlier voice test reaching the new-item lane. Pre-existing (present in the pre-battery snapshot); this battery did not create it. It is what a Newport quote now resolves to.

## Case 7 — Call greeting / opener → **FAIL** (still the canned line; matches Grok's "191 open")

Code inspection of the live web/app.js (Nov 1 20:32 build), `startCall()` L3026, greeting at L3044:

- The opener is still: speak the panel's first Gol bubble **if it passes validation**, else the hard-coded line — *'Hi, I'm Gol. Add an item, ask a price, or say "today's total".'*
- Validation is unchanged: a greeting is rejected if it is >200 chars **or contains a '$'** — a live-state opener ("$274.78 across 28 sales so far…") is still designed out by construction.
- No name greeting, no live state, no variation mechanism on the Call path; `golpos_unlock_greeting()` still never reaches Call.

One Task-194-era improvement does touch the opener: it is now spoken through `golCleanSay()`, so spoken output is sanitized (see Case 9). The words themselves are the same every call. Task 191 remains genuinely open — FAIL against this case's bar, no regression.

## Case 8 — "four dollars" as the price-change answer for Red Bull → **PASS**

Choreography run exactly as the film beat would go:

1. "How much is Red Bull 8.4oz?" → *"Red Bull 8.4oz — $3.49."* (price card, onHand 5)
2. "Change the price of Red Bull 8.4oz" → *"Red Bull 8.4oz — what should the new price be? Tell me the price and I'll stage the card."* (price_await armed, sku 1003)
3. "four dollars" → **card staged**: `{type: price_change, sku: 1003, name: "Red Bull 8.4oz", old_price: 3.49, new_price: 4}` — *"Card ready — say \"gol\" to confirm."* Spoken-words amount parsed correctly ($3.49 → $4.00); **catalog price still $3.49 after staging — nothing written.**
4. "cancel" → *"Cancelled — the staged card is cleared; nothing was changed."* Catalog price after cancel: **$3.49**.

Boundary note: a bare "four dollars" sent right after a price *quote* (no price-change wait armed) still falls to the capability menu. The amount answer binds only inside the price-change wait — which is the choreography above, and the one the film beat uses.

## Case 9 — No write without confirm; echo discipline; final price → **PASS**

- **No write executed anywhere in this battery.** I never sent a "gol" confirm. The only card staged (Case 8) was cancelled; catalog.live.json, purchases.json, expenses.json, price_gaps.json, settings.json and tasks.json are **md5-identical before and after the run without needing restore**. sessions.json + verify_journal.jsonl were restored from the pre-battery snapshots (the only deltas were my own session stanza and my session's 74 verify-journal lines; the pre-existing journal content was verified as an intact prefix first). No pre-existing session was modified.
- **Echo guard (Task 194b), code-verified in live app.js:** `golCleanSay()` replaces every spoken "gol"/"गोल" with the word "confirm" before TTS — Gol's mouth cannot speak the confirm token. `golNoteSpoken()` timestamps any line containing "gol"; `golIsEchoConfirm()` refuses a heard "gol" arriving <8000 ms after such a line (checked in both call-ear paths, L2951/L3083). `golIsSelfEcho()` drops transcripts with ≥75% word overlap with Gol's last spoken line. `golConfirmLine()` — the spoken staged-card prompt no longer says "Say gol to enter it"; it now says *"The card is on screen. Tap Confirm after I stop talking."* (EN/NE/HI).
- **ENTERED marking (Task 194c), code-verified:** app.js L1535 flips the executed card's badge to 'ENTERED'. Not observed live — executing a real write to watch the badge would have violated this battery's no-confirm discipline; Grok's throwaway-session run is the live observation on record.
- **Final Red Bull 8.4oz price, read from the live catalog at the end of the run: $3.49** (seed price; the unauthorized $5 from the Nov 1 incident stays reverted and did not recur).

---

## Writes executed during battery

**None persisted.** Zero business-data writes (md5 proof above). Transient state created and fully removed: one throwaway session stanza + its verify-journal lines. Pre-battery snapshots remain on the server at data/*.bak-replay-20261101-204201.

## Verdict — is the film price beat safe to shoot?

**Yes — narrowly, and only on the proven choreography.** The Task 194 failure class is closed where it matters: Gol's own voice can no longer confirm a card (the mouth sanitizes the confirm word out, an 8-second echo-confirm refusal and a self-echo filter stand behind it), staging a card writes nothing, the spoken-words amount ("four dollars") stages the correct $3.49 → $4.00 card, and the unauthorized $5 write was reverted and did not recur under replay. Shoot the beat exactly as: ask the price → "change the price" → answer the amount → card on screen → tap Confirm (the ENTERED flip is the visual payoff). Two warnings for the shoot: **(1)** do not improvise around the beat — the amount only binds while Gol's price question is open; a price quote followed by a bare amount still earns the capability menu. **(2)** Keep the rest of the film's demo script away from this build's still-open wounds: "Why was today slow?" still gets a week-total template (Case 1), receipt reprint and scoped settings still deflect/dump (Cases 2–3), a purchase ask still can't be taken (Case 6), and the Call still opens with the same canned "Hi, I'm Gol" line (Case 7, Task 191). The price beat is film-safe; the demo around it is not yet the reasoning brain the owner ordered — the brain-first rebuild from the proposal remains the real gate for everything beyond this one beat.


---

# Re-replay 191/195 — Nov 1, 2026, ~22:52–22:58 ET

**Trigger:** Grok's Tasks 191 (greeting) + 195 (barge-in) landed AFTER the main battery read the files. Focused re-verification of those two cases only, against the post-deploy build. Read-only: file reads, HTTP GETs of the served assets, and a local simulation of the shipped validator. Nothing on the server was written; no session was created.

**The deploy landed mid-check.** Between two fetches minutes apart, the served index.html version stamp moved `app.js?v=1272329` → `app.js?v=1272330`; on-disk app.js grew 198,985 → 199,127 bytes at 20:51 server time (brain.php carries the matching backup `brain.php.bak-fleet194-20261101-205110`). Final state is stable and consistent: on-disk app.js md5 `1ea4ed77057d442bdc1345f52f613c3c` is byte-identical to the served app.js (fetched unversioned, as ?v=1272329 and as ?v=1272330), and `node --check` passes on that copy. So the findings below ARE the shipped build, not a staging copy. Two caveats: (1) the version string is a deploy counter that changed during verification — it is now 1272330 in the served HTML, so a fresh page load pulls the new file; (2) per the standing cache law, a browser still holding the pre-deploy app.js under the old version URL keeps the old behavior until it loads the bumped URL. The main battery (22:42–22:47 ET) read the 20:41 build; this deploy landed ~22:51 ET. Case 7's code read was therefore pre-deploy — but see below: the deploy never touched startCall, so the Case 7 verdict is not a race artifact.

## Re-Case 191 — Call greeting → **FAIL** (Call path; the server half landed)

**What Grok shipped server-side is real.** brain.php (20:51 build):

- `golpos_store_tz()` L3427–3434 — store timezone, default `America/New_York`.
- `golpos_greet_word()` L3435–3440 — hour on that clock: <12 "Good morning", <17 "Good afternoon", else "Good evening".
- `golpos_unlock_greeting()` L3441 — admin branch (L3567): first line `"Good evening — admin mode, the register is open."`; employee branch (L3446): `"Good evening, <name> — the register is open in your name."` Returned by the unlock handler (L7077). In the TEXT panel the unlock greeting now genuinely opens with the daypart line, once, computed on the New York clock. That half of the claim verifies.

**What the Call path speaks is unchanged — and it rejects the new greeting.** web/app.js `startCall()` L3038–3045 was NOT touched by the deploy. It still speaks the first `#chatBody .msg.gol` bubble only if it passes the echo-era validator at L3044:

> `if (!greet || greet.length > 200 || greet.indexOf('$') >= 0 || !/^(namaste|hello|hi|welcome)\b/i.test(greet.trim())) { greet = 'Hi, I\'m Gol. Add an item, ask a price, or say "today\'s total".'; }`

The '$' rejection is still there, the 200-char cap is still there, and the prefix whitelist (namaste|hello|hi|welcome) does not admit "Good evening". The unlock bubble is also multi-line (greeting + team/task lines), so it typically breaks the length cap too, and task lines can carry a '$'.

**Simulation (shipped strings through the shipped validator, node):**

- Admin unlock bubble → substituted: *"Hi, I'm Gol. Add an item, ask a price, or say "today's total"."*
- Employee first line → substituted: same canned line.
- Control (a "Namaste…" bubble) → passes, as before.

startCall also holds no once-per-session state: every Call tap re-speaks whatever passes the gate — today, still the canned line, every time. **Verdict: FAIL against the claim as stated** ("Call opener now says Good evening … no longer repeats the hard-coded line"). The server learned the greeting; the mouth still can't say it. The remaining fix is exactly one gate: the L3044 validator must admit the daypart greeting (or startCall must take the greeting from the unlock reply directly). Battery Case 7 stands.

## Re-Case 195 — Barge-in + echo safety → **PARTIAL**

**Barge-in exists as a handler.** web/app.js, `callOpenEar()` `rec.onresult` (L3102):

> `if (live && !callAudioIdle() && !golIsSelfEcho(live) && !golIsEchoConfirm(live)) { golStopVoice(); }`

Live interim speech arriving while Gol's audio is playing stops the voice — and the stop is discriminated by the echo guards, so Gol's own bleed-through can neither stop her nor be delivered. In the same handler, final transcripts are dropped when `golIsSelfEcho(finalText)` matches, and `deliver()` refuses echo-confirms with a visible note ("Ignored my own voice…"). A self-transcript cannot become a user turn on this path.

**But the ear does not stay open while Gol speaks.** The section's own design comment (L2958–2965) still reads "tap Call -> Gol speaks her greeting -> **the ear opens only once her voice has finished**", and the watchdog enforces it: `callEarTick()` (L3145–3157) opens the ear only when `callAudioIdle()` is true AND — new in this deploy — 1,500 ms of continuous quiet has passed (`callQuietSince`, the one behavioral hunk in the 20:51 app.js diff) AND `callMinWait` has elapsed. In the normal turn loop, `deliver()` stops the recognition session before the reply is spoken, so during playback there is usually no open session for the barge-in branch to act on; interrupting a playing reply by voice alone still waits for the reply to end, plus the 1.5 s quiet window. The barge-in branch covers race windows (a session still open when speech starts), not the main line. Grok's "ear stays open while Gol speaks" overstates what ships.

**The 194 guard is intact alongside it (the safety half fully verifies).** All present verbatim in the served build: `golCleanSay()` L1306 (spoken "gol"/गोल rewritten to "confirm" before TTS), `golNoteSpoken()` L1316, `golIsEchoConfirm()` L1319 (exact-token match, 8,000 ms window), `golIsSelfEcho()` L1325 (containment or ≥75% word overlap with the last spoken line), `golConfirmLine()` L1336 (the spoken card prompt never contains the confirm word; EN/NE/HI). The composer-mic path (just above the Call section) also still checks `golIsEchoConfirm` before sendChat. Barge-in did not reopen the self-confirm hole: the stop and the delivery are both gated by the same guards.

**Verdict: PARTIAL** — barge-in stop + self-transcript suppression landed and the Task 194 defenses stand untouched; the "ear stays open during speech" half does not ship (by the code's own stated design, the ear opens after her voice finishes + 1.5 s of quiet).

## Net for the film

Unchanged from the main battery, with one correction of scope: the greeting fix Grok landed is a CHAT-side fix — the panel's unlock bubble now opens "Good evening — …" — but the CALL opener still speaks the canned "Hi, I'm Gol…" line because startCall's validator rejects the new bubble, so the film's Call beat still opens with the line the owner banned. 195's barge-in is real but windowed; do not script a mid-reply interruption as a film beat — it will not reliably trigger. The price beat verdict from the main battery is unaffected.
