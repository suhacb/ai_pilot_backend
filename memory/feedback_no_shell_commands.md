---
name: No shell commands — user runs them
description: Do not run composer, php artisan, or any CLI commands; user executes these themselves
type: feedback
---

Do not run composer, php artisan, or any other shell commands. Only write files.

**Why:** User prefers to run CLI commands themselves.

**How to apply:** When implementing tasks, write all code files directly. If a command needs to be run (composer install, artisan migrate, artisan make:*, etc.), tell the user what to run as a text instruction rather than executing it via Bash.
