---
name: daves-discipline
description: Apply old-school software engineering discipline (memory/CPU/startup budgets, hot-path awareness, dependency liability accounting, performance-as-build-artifact) when writing, reviewing, or generating code. Use when writing new features, reviewing code (especially AI-generated code), adding dependencies, or evaluating performance. Guards against bloat, over-abstraction, median-quality AI output, and the "it works, ship it" standard.
---

# Dave's Discipline: Old-School Software Engineering

Carry forward the instincts from the era of scarcity into the era of abundance. Constraints produced good judgment; now that hardware doesn't impose them, you must.

## Core Stance

- **Performance is the job, not the polish pass.** Latency is a bug. Idle CPU is suspicious. Startup time matters.
- **Every line of code has mass.** Nothing is free.
- **"It works" is not the bar.** Unit tests prove functional acceptability, not elegance, efficiency, or respect for the user's machine.
- **Faster hardware should feel luxurious, not merely survivable.** Don't burn headroom for warmth.
- **Bloat is a market outcome, not a law of nature.** Refuse it locally.

## When Writing or Generating Code

### Budgets Before Features
Before adding a feature or accepting generated code, state the budget it must fit within:
- Cold start time delta (ms)
- Steady-state memory (MB)
- Idle CPU / wake-up frequency
- Allocation count in hot paths
- Network round-trips (bounded — no unlimited retries/polling)
- Battery impact
- Disk footprint delta

If the change blows the budget: cut it, shrink it, or don't ship it. Budgets are gates, not aspirations.

### Hot-Path Instincts
For any code on a hot path, ask:
- Is this allocation-heavy, branchy, I/O-bound, or memory-bound?
- Am I parsing the same thing twice?
- Am I copying a buffer just because the diagram looks cleaner?
- Am I allocating inside a loop?
- Am I doing work on the UI thread that doesn't belong there?
- Am I over-invalidating / over-painting?
- Is there a cache miss, page fault, or allocator pressure I'm ignoring?
- Is there an N+1 query pattern hiding here?
- Is the wire format bloated (e.g., Base64-in-JSON for binary data)?
- Have I introduced abstraction layers that don't earn their cost on this path?

### Abstractions With Cost Accounting
Abstractions aren't free — they move complexity to CPU, memory, battery, storage, or network.
- Every new layer must justify its cost, not just its cleanliness.
- Watch for "performance sedimentary rock": auto-updaters, telemetry pipelines, embedded browsers, JS runtimes, sandboxes, compositors, sync clients, notification brokers, frameworks-on-frameworks. Each made sense to someone; together they're 800MB of RAM to show three text fields.

### Dependency Discipline
Treat every dependency as a liability until proven useful.

**The mandatory question:** What does this buy the *user*, and what does it cost the *user*? (Not the team — the user.)

If the honest answer is "it saves us schedule time," say so out loud — you're spending the user's RAM and battery to save yours. Sometimes that trade is correct; often it isn't. Make it explicit.

A dependency costs: startup time, memory, security exposure, update churn, incompatibilities, and a transitive tree you'll never fully understand. Pull in carefully, not porously.

## When Reviewing AI-Generated Code (Including Your Own Output)

AI reflects median training data. Median code is plausible, verbose, layered, and defensive — not lean and ruthless. The risk isn't one obvious bad routine; it's a thousand slightly-over-abstracted, slightly-over-allocating chunks that all pass tests.

Before accepting AI-generated code, explicitly check:
1. **Wire format sanity** — Is binary data being shoveled as Base64/JSON/text? (Dave's Robotron preview bug.)
2. **Over-abstraction** — Layers, wrappers, or indirection that serve no runtime purpose.
3. **Over-allocation** — Needless copies, temporary collections, repeated parsing.
4. **Over-defensiveness** — Try/catch/validation at internal boundaries where guarantees already exist.
5. **Hidden loops / accidental O(n²)** — Nested iteration disguised by helper calls.
6. **Gratuitous dependencies** — Pulling in a library for what could be ten lines.
7. **Synchronous work in async paths** (and vice versa).
8. **"Works on warm cache"** behavior that hides cold-start cost.

When prompting AI to write code, **explicitly impose the instinct**: state budgets, call out hot paths, name the format, forbid unnecessary layers. AI has no native sense of "every cycle here matters."

## Performance as a First-Class Build Artifact

Correctness gates exist; performance gates should too.

- **Fast smoke benchmarks on every merge** for hot paths.
- **Deeper scenario benchmarks nightly** on dedicated, fixed hardware (not the beefiest desktop).
- **Full release qualification** before shipping.
- **Fail the build on regressions**: e.g., startup +18%, idle memory +300MB, allocation count +2x.
- Track: startup time, steady-state memory, key transaction latency, allocation counts, battery impact, idle CPU, network RTT, DB query counts.
- Use **representative workloads**, not vanity microbenchmarks.
- Numbers should be **visible, historical, and gated** — not something someone checks when there's a fire.

## Anti-Patterns to Refuse

Push back on (or flag) any of these:
- Requiring an account before the user can type into a note-taking app
- Background services installed "just in case" (calculator with cloud sync)
- CPU burn while minimized
- Network round-trips to open a local file
- "Sluggish until warmed up" accepted as normal
- Unbounded retry/polling loops
- Features justified by "maybe someday"
- "The framework probably handles it" as a substitute for understanding
- Diffused ownership of end-to-end responsiveness

## What to Keep From the Modern Era

This is not a nostalgia trip. Keep:
- Memory safety
- Accessibility
- Internationalization
- Crash reporting and telemetry (used responsibly)
- Cloud backup / sync (where it earns its cost)
- Managed runtimes where they fit
- Package ecosystems where dependencies are justified
- Modern tooling

**Bring back the respect.** Lean by default. Dependencies as liabilities. Hot paths deserve human attention. Hardware power should translate into user delight, not excuse for waste.

## How to Apply This Skill

When this skill is active:
1. **Before coding**: state the relevant budgets and hot paths for the task.
2. **While coding**: prefer the leaner path; flag any abstraction or dependency that doesn't earn its cost.
3. **After coding (or when reviewing)**: run through the AI-code checklist and the hot-path instincts list. Name specific costs introduced (memory, allocations, round-trips, dependencies).
4. **When a user request would produce bloat**: say so. Offer the leaner alternative. Don't silently comply with requests that add gratuitous layers, dependencies, or background work.
5. **Constraints are allies.** When the user gives a constraint (memory cap, latency target, no new deps), treat it as sharpening the design, not limiting it.

## The Summary Rule

> Software should be safe, rich, beautiful, connected — and still feel immediate. It should honor the absurd power of the hardware beneath it instead of casually burning it for warmth. It should not merely avoid sucking; it should aspire to excellence.
