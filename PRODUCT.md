# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Primary: prospective clients and employers evaluating Mohammed Ahmed as a frontend & AI expert via the public portfolio, deciding whether to hire or contact him.
Secondary: Mohammed Ahmed himself, as the sole operator of the private admin dashboard ("Canary"), managing projects, client communication, and meetings.

*(Inferred from README; not confirmed live — the interview was offered but declined with no preference given. Revisit if this framing is wrong.)*

## Product Purpose

Revil is a premium personal developer-portfolio ecosystem: a public-facing showcase of Mohammed Ahmed's work, paired with a private real-time admin dashboard for running his freelance/professional pipeline (projects, client messages, meetings, engagement tracking).

*(Inferred from README.)*

## Positioning

A "Ceramic-Glass" glassmorphism visual identity combined with the private Canary engine's session tracking, trackable per-client links ("Link Architect"), and meeting scheduling — a mechanism most developer portfolios don't have (they're typically static showcases with no operating layer behind them).

*(Inferred from README; treat as a hypothesis, not confirmed positioning — the differentiator question was left unanswered.)*

## Operating Context

- Public showcase (portfolio, projects, case-style presentation) + a separate private admin area, both served from one Next.js app.
- Firebase backend: Firestore (data), Storage (files/images), Cloud Functions.
- Deployed via Docker; hosting instructions reference Hostinger (see `HOSTINGER_SETUP.md`).
- Domain referenced in README: temrevil.com.

## Capabilities and Constraints

Confirmed from repo files:
- Stack: Next.js (16.x per package.json, README says 15), React 19, TypeScript, Tailwind CSS v4 alongside a hand-rolled CSS design system, Firebase, Anime.js + Motion for animation.
- Security posture is explicitly load-bearing per README: hardened Firestore/Storage rules (`firestore.rules`, `storage.rules`), CSP compliance, sanitized SVG rendering, a `ClientProtection` DOM-mutation shield against hostile browser extensions.
- MIT licensed.

Explicitly undecided / open (do not invent an answer):
- **This repository currently contains no application source code** — no `src/` directory exists on `main`. Git history shows a zip of source was uploaded once (`Add files via upload`) and later deleted (`Delete 2_5258252641435161863.zip`); only config, Docker, Firebase rules, README, and license remain. Whether the real Next.js source lives in another repo/branch, needs to be rebuilt from scratch here, or is simply not added yet was asked directly and left unanswered. Any new-work or build command against this repo should confirm this before assuming either greenfield or existing-code context.

## Evidence on Hand

- `README.md` is detailed and aspirational but partly describes things not present in this checkout — treat its architecture diagram and feature list as design intent, not verified current state.
- README claims "Zero-Error Automation" via GitHub Actions on every push to `main` (lint/build/TypeScript gates, failure emails). **This is not currently true of the repo**: git history shows `.github` (including a `workflows` directory) was added and then deleted, and no `.github/` directory exists now. Do not assume CI exists.
- No customer testimonials, case studies, metrics, or press are present anywhere in the repo. Do not fabricate any.

## Product Principles

- Distinctive, premium craft over generic template aesthetics — the "Ceramic-Glass" identity is a stated commitment, not decoration.
- Security and integrity are part of the product's pitch, not an invisible implementation detail (hardened rules, DOM protection, CSP).
- The admin dashboard is a real operating tool for Mohammed's own workflow, not a demo feature — it should behave like software he depends on daily.
- Adaptive precision: the experience is committed to working well from small mobile screens up through large/4K displays.

