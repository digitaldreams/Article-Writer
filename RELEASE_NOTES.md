# Release Notes

## 2.1 — Onboarding — 2026-10-19

New authors sign up and set up their author profile before they can plan: the app writes only from instructions the author approved.

**Upgrade:** run `bin/console doctrine:migrations:migrate`. Existing authors are marked onboarded and skip the wizard.

- **Register.** `/register`: email, password, repeat password. You are logged in right after; linked from the login page.
- **Onboarding wizard.** `/welcome`, in order: import past articles (required) → bio → analyze your writing → review and approve the writing profile. Every other page sends you back to your current step until you approve, also after logging out and in. The analysis can take hours on a local model; you can leave and come back.
- **Analyze waits for imports.** Analyze my writing is 409 while an import is still running.

## 2.0 — Author — 2026-10-18

The app learns how you write: import your published articles, analyze them, and the article and rewrites follow your voice.

**Upgrade (in this order)**
1. Stop the workers and let the queues drain (no queued or working job left).
2. Run `bin/console doctrine:migrations:migrate`. It deletes the old setup job history (import and structure jobs) and queued setup messages: their message classes were renamed and can't be retried.
3. Start the workers, open `/author/profile` and click **Analyze my writing**. The old structure notes are dropped (they have no voice), so every library article is analyzed again: one secondary-model call per article, slow on a local model. Your structure profile is kept until the new one replaces it.

**Author** (was Setup)
- **Bio.** `/author` (was `/setup`); `/setup` is gone.
- **Library.** `/author/library` lists your imported articles: date published, source link, analyzed yes/no, Delete (409 while the article is being analyzed). Imports dedupe by source URL; a Medium post is the same article from the zip and the feed.
- **Medium zip.** Upload the Medium export `.zip` on the Bio page. Posts become markdown (headings, fenced code); drafts, `__MACOSX` files and short responses (no heading, under 150 words) are skipped. Upload limit 64 MB: split a bigger zip.
- **Feeds.** `/author/feeds`: add RSS or Atom feeds, **Refresh** imports new items. Medium feeds hold only your latest 10 posts: use the zip for older ones.
- **Analyze my writing.** A button on `/author/profile` builds a writing profile: structure (opening, ordering, sections, running example, closing) and voice (tone, sentence style, devices, code habit, samples). Analyze again replaces your hand edits; voice fields are optional.
- **Prompts.** `/author/prompts` shows every default system prompt, read-only.

**Writing**
- **Voice when writing.** The article and section rewrites get a "Your voice" section; the outline gets only the structure. The models are told never to copy the samples.

## 1.0 — First release — 2026-10-18

Plan → write → polish a technical article with local or hosted LLMs; every LLM step runs as a background job.

**Accounts**
- **Login, per-author data.** Each author sees only their own plans, profile and jobs.

**Setup**
- **Writer profile.** Save profile at `/setup`; edit the structure profile at `/setup/profile`.
- **Import past articles.** Upload `.md` files or a folder; each file is analysed, then merged into one structure profile.

**Planning**
- **Create plan.** Topic + idea; title generated in background.
- **Brainstorm.** Add notes and reference URLs; each page is fetched and digested. Read references can be removed while brainstorming.
- **Questions (optional).** Interviewer + reader questions; a solid idea can skip straight to the outline.
- **Outline.** Generate rounds, add/edit items, change direction, then approve. Approve writes the plan `.md` files first; a failed write commits nothing.
- **Write article.** One LLM call writes `article.md` from the approved plan.

**After writing**
- **Edit + download.** Body autosaves on the article page (front matter untouched); download as `<title-slug>.md`.
- **Improve.** Reader review, plus per-section rewrites you accept or reject. Accept refuses if the section changed since you asked, so hand edits are never overwritten.
- **Titles, Poster, Share.** Title/subtitle suggestions, poster ideas (PNG drawn only when `AI_POSTER_IMAGE_MODEL` names an image-capable model), share punchlines.

**LLM + jobs**
- **Background jobs with Retry.** Queues `llm_primary`, `llm_secondary`, `io`; failures show as a job card with Retry, never an HTTP error.
- **Auto-retry once** after 5 s, only on connection/timeout failures.
- **Stall warning.** Shown when a non-article job runs > 30 min, or jobs are queued with no worker heartbeat for 2 min. Article job is never flagged.
- **Pick platform per role.** `AI_{PRIMARY,SECONDARY,TITLE,POSTER}_PLATFORM` / `_MODEL`: Ollama, OpenAI, Anthropic, Gemini.
- **Fixed Ollama options** on every call: `num_ctx` 16384, `num_thread` 6, `keep_alive` -1, so step switches don't reload the model.
- **Cost per article.** Shown on the step tabs, priced from `config/packages/ai_prices.php`; Ollama counts as free.
- **Prompts in files.** Every system prompt is a hand-editable `prompts/<agent>_agent.md`, read when a worker starts. Note the trade: prompt edits need a worker restart.
