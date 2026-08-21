# Admin UI redesign — implementation plan (amendment)

Scope: visual redesign only. No API/route/data-model changes. Every change below is CSS/className/markup-level inside `web/src/**`. Reference mockup: `Family Quiz Admin Redesign.dc.html` (Slate accent variant).

## 1. Design tokens — `web/src/styles/index.css`

Replace the `@theme` block:

```css
@theme {
  --font-display: "Archivo", ui-sans-serif, system-ui, sans-serif; /* was Fraunces */
  --font-sans: "Archivo", ui-sans-serif, system-ui, sans-serif;    /* was Source Sans 3 */
  --color-ink: #201e1d;
  --color-paper: #f3f2f2;
  --color-accent: #3b5a7a;       /* was teal #0f766e — slate blue */
  --color-accent-soft: #eaf0f6;  /* was #ccfbf1 */
  --color-accent-strong: #243a50;/* new — hover/active/pressed */
  --color-warm: #a8722a;         /* optional secondary tag color, was orange #c2410c */
  --color-muted: #78716c;
  --color-line: color-mix(in srgb, #201e1d 22%, transparent); /* was flat #e7e5e4 — a visible 2px rule reads better as a semi-opaque ink line */
}
```

Remove the decorative radial-gradient background on `body` — replace with a flat paper ground:

```css
body {
  margin: 0;
  font-family: var(--font-sans);
  background: var(--color-paper);
  color: var(--color-ink);
}
```
(Drop the whole `background: radial-gradient(...)` block and its dark-mode gradient twin.)

Add a shared rule utility (new):
```css
.rule { height: 2px; background: var(--color-line); border: 0; margin: 1.5rem 0; }
```

## 2. Global pattern changes (apply everywhere these appear)

| Old pattern | New pattern | Why |
|---|---|---|
| `rounded-xl` / `rounded-2xl` on cards, inputs, buttons | `rounded-none` (drop the class) | flat, architectural feel — no corners |
| `border border-[var(--color-line)]` boxes | keep border, but move from rounded cards to flush rows/rules where the content is list-like; keep `rounded-none` | |
| `text-3xl font-bold` page titles (`<h1>`) | `text-2xl font-bold` (or `text-[22px]`) | smaller header per feedback |
| section `<h2>` `text-xl` | `text-lg` | matches reduced heading scale |
| Buttons: `bg-[var(--color-accent)] text-white font-semibold` primary | keep, but add `hover:bg-[var(--color-accent-strong)] active:bg-[var(--color-accent-strong)]` and left-align label (`justify-start` when button is full-width) instead of centered | themed hover/press state, flush-left labels |
| Amber "seed password" banner (`bg-amber-100 text-amber-950`) | `bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]` | keep the accent family consistent, one accent role instead of an ad-hoc amber |
| Status text like `text-teal-700`, `text-red-700`, `bg-teal-600` (Results, Content, AdminPresentPage) | swap teal → `text-[var(--color-accent-strong)]` / `bg-[var(--color-accent)]`; keep red only for genuine destructive actions (delete) | one accent role app-wide instead of teal+red+amber mixed |
| Focus rings (default browser blue) | add global `:focus-visible { outline: 2px solid var(--color-accent); outline-offset: 2px; }` in `index.css` | themed focus, not browser default |

Icons: none are used today. Recommend adding `lucide-react` (`npm i lucide-react`) and using it for: chevrons (reorder), plus (create/add), trash-2 (delete), download (export), rotate-cw (recompute), arrow-left (back links), log-out. Purely additive — every button still works with just text if icons are skipped for a first pass.

## 3. Per-file changes

### `AdminShell.tsx`
- Header: drop `backdrop-blur`/translucency (`bg-white/70`) → solid `bg-[var(--color-paper)]` with `border-b-2 border-[var(--color-line)]` (a stronger 2px rule instead of a hairline), so scrolled content never shows through — this was the first bug we hit in the mockup, worth guarding here too.
- Nav links: on the active route, apply `text-[var(--color-accent-strong)] font-semibold` (currently no active-state styling at all — add it via `useLocation()`/`NavLink`).
- `AdminLoginPage`: card `rounded-2xl` → `rounded-none`; `<h1>` `text-2xl` → `text-xl`; keep the rest.
- Seed-password banner: recolor per table above.

### `AdminProjectsPage.tsx`
- `<h1>` `text-3xl` → `text-2xl`.
- Project `<li>` cards: `rounded-2xl` → `rounded-none`; consider replacing the per-project card border with a `divide-y divide-[var(--color-line)]` list (flush rows) instead of individually bordered boxes — reads closer to the mockup's flat list.
- State buttons (`SETUP/ACTIVE/CLOSED/REVEALED`): the selected one currently uses `bg-stone-900 text-white`; change to `bg-[var(--color-ink)] text-[var(--color-paper)]` (keep, just token-ize) and make it visually a segmented control: wrap the four buttons in one flush border, no individual button borders, only a divider between them.
- "Create" button: primary style, flush-left label, plus icon.

### `AdminContentPage.tsx` (list) + `AdminQuizEditorPage`
- List: convert the `<ul>` of bordered `<li>` rows into a `<table>` (columns: #, Title, Status, Reorder) matching the mockup — clearer scanning than stacked cards for 6+ quizzes.
- Completeness indicator: `text-teal-700 text-sm ✓` → an accent-tinted pill (`bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)] px-2 py-0.5 text-xs`); incomplete stays a neutral/outline pill with the issue text, not amber.
- Editor page: `max-w-3xl` is fine; reduce `<h1>` to `text-2xl`; group the option cards' borders consistently (`rounded-none`); "Delete quiz" stays a plain destructive text link but move it to `text-red-700` only (the one place red should still appear).

### `AdminLivePage.tsx`
- `<h1>` `text-3xl` → `text-2xl`.
- Participant rows: `rounded-xl` → `rounded-none`; progress bar track `bg-[var(--color-line)]`, fill `bg-[var(--color-accent)]` (already close — just drop the corner radius on both track and card).

### `AdminResultsPage.tsx`
- `<h1>` `text-3xl` → `text-2xl`; `<h2>` `text-xl` → `text-lg`.
- Leaderboard: convert the `<ol>` of bordered buttons into a `<table>` (Rank, Name, Score), row click still opens the drill-down.
- Drill-down: currently dumps raw `JSON.stringify` — replace with a small labeled list (quiz title → participant's pick → correct/incorrect tag), styled with the same table pattern. This was a placeholder in the original; the mockup shows the intended friendlier version.
- Per-quiz stat bars: `bg-teal-600` correct / `bg-stone-400` other → `bg-[var(--color-accent)]` / `bg-[var(--color-line)]`.
- "Recompute" (secondary) / "Export ZIP" (primary): token-ize colors, add icons, no radius.

### `AdminUsersPage.tsx`
- `<h1>` `text-3xl` → `text-2xl`.
- Table already close to plan (rows are `<li>`) — convert to `<table>` (Email, Status, Actions) matching Results/Content for consistency.
- Status: `is_active` → accent pill ("Active") vs neutral pill ("Disabled") instead of plain text.

### `AdminPresentPage.tsx`
Two supported tones (add a small in-page toggle, not participant-facing):
- **Theatrical** (current): keep `bg-stone-950 text-stone-50`, but swap `text-teal-300` / `border-teal-400 bg-teal-950/50` → a light accent tint appropriate on dark, e.g. `border-[#7fa3c9] bg-[#1c2733] text-[#bcd2e8]` (a lightened slate, not teal).
- **Modernist** (new, optional second mode): light background (`bg-[var(--color-paper)] text-[var(--color-ink)]`), correct-option highlight uses `border-[var(--color-accent)] bg-[var(--color-accent-soft)]`.
- Both: reduce `text-4xl md:text-5xl` slide titles → `text-3xl md:text-4xl` per the smaller-heading feedback; keep body copy sizes as-is (projector legibility matters more there).

## 4. Suggested order of work
1. Token + global CSS changes (`index.css`) — unlocks every downstream page for free since most colors are already `var(--color-*)`.
2. `AdminShell.tsx` (header/nav/login) — visible on every screen.
3. Table conversions (Content, Results, Users) — the biggest structural change.
4. Remaining per-page polish (radii, heading sizes, pill colors).
5. `AdminPresentPage` tone toggle last — self-contained, no shared dependency.

## 5. Explicitly out of scope
- No API/route changes, no new endpoints, no data model changes.
- TipTap editor internals untouched — only its container's border-radius changes (`rounded-xl` → `rounded-none` wrapper) if currently applied in `TipTapEditor.tsx`.
- Icon library addition (`lucide-react`) is a suggestion, not a requirement — every change above works with text-only buttons too.
