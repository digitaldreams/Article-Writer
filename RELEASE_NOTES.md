# Release Notes

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
