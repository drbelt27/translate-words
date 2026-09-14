# Project instructions

- Reply to the user in Italian. Keep responses concise, clear, and focused on decisions and verified findings.
- Write all code, code comments, identifiers, and documentation added to the repository in English. Make user-facing plugin strings translatable.
- Follow the existing style and architecture of the affected files. Use the `translate-words` text domain and existing Linguator APIs and hooks.
- Read relevant guidance in `.claude/rules/` and skills in `.codex/skills/`. Verify assumptions against the actual source.
- `.claude/CLAUDE.md` currently describes a different plugin (`autodeploy-testing`). Its project name, architecture, version requirements, text domain, and claims about missing build tools do not describe this repository. Use `translate-wp-words.php`, `composer.json`, and `package.json` as the source of truth.
- For issue #19, analyze and agree on the design before implementing plugin behavior. The initial scope includes simple products, variable products, and existing variation IDs.
- Preserve existing work and avoid unrelated refactoring. Distinguish source inspection from runtime verification.
